<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use RuntimeException;

/**
 * A cache store refused to keep the record of an idempotency key, and said so by returning false rather than throwing.
 *
 * memcached refuses an item above its item size that way. The request has acted and its client has the answer, but a
 * repeat under the same key is answered with `409` instead of that answer. So the refusal is reported, as a store that
 * throws on the write is, and never thrown.
 */
final class AnswerNotKept extends RuntimeException
{
    /**
     * @param  string  $store  the name of the cache store that refused the write
     * @param  int|null  $bytes  the size of the body the store was to keep, or null for a record that keeps none
     */
    public static function by(string $store, ?int $bytes): self
    {
        if ($bytes === null) {
            return new self("The cache store [{$store}] refused to keep the record of an idempotency key. The key stays held by the record of its running request, and a repeat of the request takes it over once in_flight_ttl has passed and acts a second time.");
        }

        return new self("The cache store [{$store}] refused to keep an answer of {$bytes} bytes under an idempotency key. A repeat of the request is answered with 409 instead of the answer.");
    }
}
