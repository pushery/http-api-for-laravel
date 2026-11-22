<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Pushery\HttpApi\Errors\ErrorDocument;
use Pushery\HttpApi\Errors\JsonApiError;
use Pushery\HttpApi\Errors\ProvidesErrorDocument;
use RuntimeException;

/**
 * Why a request with an `Idempotency-Key` was not let through, with the statuses the draft names: `400`
 * `malformed_request` for a key that is missing where it is required or is malformed, `409` `request_in_flight` while
 * the first request under the key is still running, with `Retry-After`, `409` `conflict` when its answer could not be
 * kept, and `422` `validation_failed` for the same key with another request. Each of them names its case with the
 * catalog's detail and the header the key is read from in `source.header`. Beside them, `500` `internal_error` when
 * the store that keeps the keys refuses to take one: the request was not carried out, and the client sends it again
 * later under the same key.
 *
 * `reason` names the case for a log or a test.
 */
final class IdempotencyRefusal extends RuntimeException implements ProvidesErrorDocument
{
    /**
     * @param  string  $reason  the case, `idempotency-key-missing` for instance
     * @param  string  $errorCode  the code of the catalog the case is answered with
     * @param  string  $detail  the detail of the code that names the case
     * @param  bool  $namesTheKey  whether the error names the header the key is read from
     * @param  int|null  $retryAfter  the seconds after which a request held back by a running one is worth sending again
     */
    private function __construct(
        public readonly string $reason,
        private readonly string $errorCode,
        private readonly string $detail = 'default',
        private readonly bool $namesTheKey = true,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($reason);
    }

    public static function missing(): self
    {
        return new self('idempotency-key-missing', 'malformed_request', 'idempotency_key_missing');
    }

    public static function malformed(): self
    {
        return new self('idempotency-key-malformed', 'malformed_request', 'idempotency_key_malformed');
    }

    /**
     * The first request under the key is still running.
     *
     * @param  int  $retryAfter  the seconds until its hold on the key runs out, one at least
     */
    public static function inFlight(int $retryAfter): self
    {
        return new self('idempotency-key-in-flight', 'request_in_flight', retryAfter: max(1, $retryAfter));
    }

    public static function notKept(): self
    {
        return new self('idempotency-answer-not-kept', 'conflict', 'idempotency_answer_not_kept');
    }

    public static function reused(): self
    {
        return new self('idempotency-key-reused', 'validation_failed', 'idempotency_key_reused');
    }

    public static function notTaken(): self
    {
        return new self('idempotency-key-not-taken', 'internal_error', namesTheKey: false);
    }

    public function toErrorDocument(): ErrorDocument
    {
        $header = IdempotencyHeader::name(Container::getInstance()->make(Repository::class));
        $values = $this->namesTheKey ? ['header' => $header] : [];

        if ($this->retryAfter !== null) {
            $values['retry_after'] = (string) $this->retryAfter;
        }

        return ErrorDocument::of(JsonApiError::of($this->errorCode, $this->detail, $values, $this->namesTheKey ? ['header' => $header] : []));
    }
}
