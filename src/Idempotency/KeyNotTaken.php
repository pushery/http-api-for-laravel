<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use RuntimeException;

/**
 * A cache store refused to take an idempotency key, twice, and held no record under it: reported, never thrown.
 *
 * A store refuses its writes without throwing that way, as a full Redis under `noeviction` does through phpredis, which
 * answers the out-of-memory error with false. Nothing has acted, and the client is answered with `503` and asked to
 * come back later, so the report is where the refusing store shows up.
 */
final class KeyNotTaken extends RuntimeException
{
    /** @param  string  $store  the name of the cache store that refused the key */
    public static function by(string $store): self
    {
        return new self("The cache store [{$store}] refused to take an idempotency key twice and held no record under it, as a store that refuses its writes does, a full Redis under noeviction through phpredis among them. The request was answered with 503 and not carried out.");
    }
}
