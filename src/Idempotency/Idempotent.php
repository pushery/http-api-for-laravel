<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository as Store;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A write acts once, however often it is sent: the `Idempotency-Key` of draft-ietf-httpapi-idempotency-key-header.
 *
 * The first request under a key is the only one that acts. A second one with the same key and the same request, a
 * retry after a timeout or a double click, gets the first answer again, marked `Idempotent-Replayed: true`, and
 * changes nothing. The same key with another request is refused with `422`, because answering it with the first result
 * would report an action that was never asked for. While the first request runs, a second one is told so with `409`.
 *
 * Only an answer below `400` is kept. A refused or failed request frees its key, so the corrected request can be sent
 * under it again. The key is taken for `in_flight_ttl` while its first request runs, so a process that dies midway
 * frees it, and the answer is kept for `ttl`. A store that throws while it frees the key is reported, the request keeps
 * its own answer, and the key stays taken until `in_flight_ttl` runs out. An answer there is nothing to keep of, a
 * stream or a file, and one the store cannot hold, larger than its item size or lost to a store that failed, still
 * reach the client, and the key stays used for `ttl`: a repeat is told so with `409` instead of acting a second time.
 * A store that refuses the write or throws on it is reported. A store that takes no key at all, as a full Redis under
 * `noeviction` does, is reported too, and the request is answered with `503` without acting.
 *
 * A key belongs to the scope {@see ResolvesIdempotencyScope} names, so two clients with the same key never meet each
 * other's answer. `POST` and `PATCH` need a key; `PUT` and `DELETE` come to the same end however often they are sent
 * and need none, though one they carry is held like any other. The methods `Request::isMethodSafe()` names, `GET`,
 * `HEAD`, `OPTIONS`, `TRACE` and `QUERY`, change nothing a key would protect and pass untouched. With the parameter
 * `optional`, `Idempotent::class.':optional'`, no method needs a key.
 */
final readonly class Idempotent
{
    public const string REPLAYED = 'Idempotent-Replayed';

    /** The methods that need a key unless the route makes it optional. */
    private const array NEED_A_KEY = ['POST', 'PATCH'];

    public function __construct(
        private Repository $config,
        private Factory $cache,
        private ResolvesIdempotencyScope $scopes,
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws IdempotencyScopeMissing when a request carries a key and nothing says who sent it
     */
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        if ($this->config->get('http-api.enabled') === false || $request->isMethodSafe()) {
            return $next($request);
        }

        $key = $this->unquoted(trim((string) $request->headers->get(IdempotencyHeader::name($this->config), '')));

        if ($key === '') {
            return $mode !== 'optional' && in_array($request->method(), self::NEED_A_KEY, true)
                ? IdempotencyRefusal::missing()->toProblem()->toResponse()
                : $next($request);
        }

        // `\z`, not `$`: the trim above runs before the quotes come off, so a line feed inside them stays in the key,
        // and `$` also matches before a final line feed.
        if (strlen($key) > $this->longest() || preg_match('/^[\x20-\x7E]+\z/', $key) !== 1) {
            return IdempotencyRefusal::malformed()->toProblem()->toResponse();
        }

        $scope = $this->scopes->scopeOf($request);

        if ($scope === null) {
            throw IdempotencyScopeMissing::on($request->path());
        }

        $store = $this->cache->store($this->storeName());
        $slot = 'http-api:idempotency:'.hash('sha256', $scope."\n".$key);
        $fingerprint = RequestFingerprint::of($request);

        // Twice at most: a key the first look finds taken can be freed before it is read, when the request that held
        // it failed in between. The second look then takes it like a first request.
        for ($look = 0; $look < 2; $look++) {
            if ($store->add($slot, ['fingerprint' => $fingerprint, 'answer' => null], $this->positive('in_flight_ttl', 600))) {
                return $this->act($request, $next, $store, $slot, $fingerprint);
            }

            $held = $store->get($slot);

            if ($held !== null) {
                return $this->answerAgain($held, $fingerprint);
            }
        }

        // Neither look took the key, and neither found a record under it: the store refuses its writes without
        // throwing, as a full Redis under `noeviction` does through phpredis. Nothing has acted, so the client is asked
        // to come back later, and the refusing store is reported.
        $this->exceptions->report(KeyNotTaken::by($this->storeLabel()));

        return IdempotencyRefusal::notTaken()->toProblem()->toResponse();
    }

    /**
     * Run the first request under a key and keep its answer, or free the key where it did not act.
     *
     * @param  Closure(Request): Response  $next
     */
    private function act(Request $request, Closure $next, Store $store, string $slot, string $fingerprint): Response
    {
        // A failure inside the route reaches this point as its answer, never as an exception: Laravel's routing
        // pipeline renders an exception into a response in the slice that threw it. So an answer of 400 or more is
        // what frees the key of a request that failed, whatever made it fail.
        $response = $next($request);

        if ($response->getStatusCode() >= 400) {
            $this->free($store, $slot);

            return $response;
        }

        $content = $response->getContent();
        $ttl = $this->positive('ttl', 86_400);

        // A streamed answer and a file have no content to read here, so a repeat can only be told that the request
        // was carried out.
        if (! is_string($content)) {
            $this->keep($store, $slot, ['fingerprint' => $fingerprint, 'answer' => false], $ttl);

            return $response;
        }

        // A body that is no valid UTF-8 is kept as base64: a database store writes the record into a text column, and
        // PostgreSQL and MySQL refuse such bytes there.
        $base64 = preg_match('//u', $content) !== 1;

        $answer = [
            'status' => $response->getStatusCode(),
            'content' => $base64 ? base64_encode($content) : $content,
            'base64' => $base64,
            'headers' => $this->replayedHeadersOf($response),
        ];

        // The request has acted by now, so a store that cannot keep its answer must not let a repeat act again: the
        // key then stays used for as long as the answer would have been kept, without the answer.
        if (! $this->keep($store, $slot, ['fingerprint' => $fingerprint, 'answer' => $answer], $ttl)) {
            $this->keep($store, $slot, ['fingerprint' => $fingerprint, 'answer' => false], $ttl);
        }

        return $response;
    }

    /**
     * Free the key of a request that did not act, and report a store that throws on it rather than replacing the
     * request's own answer with a failure. The key then stays taken until `in_flight_ttl` runs out.
     */
    private function free(Store $store, string $slot): void
    {
        try {
            $store->forget($slot);
        } catch (Throwable $failure) {
            $this->exceptions->report($failure);
        }
    }

    /**
     * Write a key's record, and report a store that refuses it or throws rather than failing a request that has already
     * acted.
     *
     * @param  array{fingerprint: string, answer: array{status: int, content: string, base64: bool, headers: array<string, string>}|false}  $record
     */
    private function keep(Store $store, string $slot, array $record, int $ttl): bool
    {
        try {
            $kept = $store->put($slot, $record, $ttl);
        } catch (Throwable $failure) {
            $this->exceptions->report($failure);

            return false;
        }

        // A store can refuse a write without throwing: memcached answers an item above its item size with false.
        if (! $kept) {
            $this->exceptions->report(AnswerNotKept::by($this->storeLabel(), is_array($record['answer']) ? strlen($record['answer']['content']) : null));
        }

        return $kept;
    }

    /**
     * The answer to a key that was used before: the first answer, or why there is none to give.
     *
     * @param  mixed  $held  the record the cache store returned, typed mixed by PSR-16 and checked here field by field
     */
    private function answerAgain(mixed $held, string $fingerprint): Response
    {
        $held = is_array($held) ? $held : [];

        if (($held['fingerprint'] ?? null) !== $fingerprint) {
            return IdempotencyRefusal::reused()->toProblem()->toResponse();
        }

        $answer = $held['answer'] ?? null;

        if ($answer === false) {
            return IdempotencyRefusal::notKept()->toProblem()->toResponse();
        }

        if (! is_array($answer)) {
            return IdempotencyRefusal::inFlight()->toProblem()->toResponse();
        }

        $headers = [];

        foreach (is_array($answer['headers'] ?? null) ? $answer['headers'] : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        $content = is_string($answer['content'] ?? null) ? $answer['content'] : '';

        return new IlluminateResponse(
            ($answer['base64'] ?? false) === true ? (string) base64_decode($content, true) : $content,
            is_int($answer['status'] ?? null) ? $answer['status'] : 200,
            [...$headers, self::REPLAYED => 'true'],
        );
    }

    /**
     * The headers a repeated request gets back: the configured ones, never `Set-Cookie`.
     *
     * @return array<string, string>
     */
    private function replayedHeadersOf(Response $response): array
    {
        $names = $this->config->get('http-api.idempotency.replayed_headers', []);
        $headers = [];

        foreach (is_array($names) ? $names : [] as $name) {
            if (! is_string($name) || strcasecmp($name, 'Set-Cookie') === 0) {
                continue;
            }

            $value = $response->headers->get($name);

            if (is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /** A key written as a structured-field string, `"…"`, without its quotes; any other key as it is. */
    private function unquoted(string $key): string
    {
        return strlen($key) >= 2 && str_starts_with($key, '"') && str_ends_with($key, '"') ? substr($key, 1, -1) : $key;
    }

    private function storeName(): ?string
    {
        $store = $this->config->get('http-api.idempotency.store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /** The name of the store the keys are kept in, as a report names it: the one configured, or the default store. */
    private function storeLabel(): string
    {
        $default = $this->config->get('cache.default');

        return $this->storeName() ?? (is_string($default) ? $default : 'default');
    }

    private function longest(): int
    {
        return $this->positive('max_key_length', 255);
    }

    /** A positive whole number from the idempotency settings, or the shipped value where the setting is none. */
    private function positive(string $key, int $shipped): int
    {
        $value = $this->config->get('http-api.idempotency.'.$key);

        return is_int($value) && $value >= 1 ? $value : $shipped;
    }
}
