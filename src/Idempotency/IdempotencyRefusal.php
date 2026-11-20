<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Pushery\HttpApi\Problems\CatalogLanguage;
use Pushery\HttpApi\Problems\Problem;
use Pushery\HttpApi\Problems\ProvidesProblem;
use RuntimeException;

/**
 * Why a request with an `Idempotency-Key` was not let through, as the statuses the draft names: `400` for a key that
 * is missing where it is required or is malformed, `409` while the first request under the key is still running or
 * when its answer could not be kept, and `422` for the same key with another request. Beside them, `503` when the store
 * that keeps the keys refuses to take one, so the request was not carried out and can come back later.
 */
final class IdempotencyRefusal extends RuntimeException implements ProvidesProblem
{
    /** How long a client waits before it sends a request again that the store refused to take a key for, in seconds. */
    private const int RETRY_AFTER = 30;

    /**
     * @param  array<string, string>  $headers  headers of the answer beside `Content-Language`, such as `Retry-After`
     */
    private function __construct(
        public readonly string $problem,
        public readonly int $status,
        private readonly array $headers = [],
    ) {
        parent::__construct($problem);
    }

    public static function missing(): self
    {
        return new self('idempotency-key-missing', 400);
    }

    public static function malformed(): self
    {
        return new self('idempotency-key-malformed', 400);
    }

    public static function inFlight(): self
    {
        return new self('idempotency-key-in-flight', 409);
    }

    public static function notKept(): self
    {
        return new self('idempotency-answer-not-kept', 409);
    }

    public static function reused(): self
    {
        return new self('idempotency-key-reused', 422);
    }

    public static function notTaken(): self
    {
        return new self('idempotency-key-not-taken', 503, ['Retry-After' => (string) self::RETRY_AFTER]);
    }

    /** The problem, its texts naming the header the middleware reads the key from. */
    public function toProblem(): Problem
    {
        $container = Container::getInstance();
        $config = $container->make(Repository::class);
        $base = $config->get('http-api.problems.type_base');
        $translator = $container->make(Translator::class);
        $header = ['header' => IdempotencyHeader::name($config)];
        $title = $translator->get('http-api::messages.'.$this->problem.'.title', $header);
        $detail = $translator->get('http-api::messages.'.$this->problem.'.detail', $header);

        return new Problem(
            $this->status,
            type: (is_string($base) ? $base : '').$this->problem,
            title: is_string($title) ? $title : null,
            detail: is_string($detail) ? $detail : null,
            headers: [...$this->headers, 'Content-Language' => CatalogLanguage::of($translator)],
        );
    }
}
