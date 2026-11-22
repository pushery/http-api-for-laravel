<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use Illuminate\Contracts\Config\Repository;

/**
 * The name of the header a client sends its idempotency key in.
 *
 * `Idempotency-Key`, as the draft names it, unless `http-api.idempotency.header` names another. The middleware reads
 * the key from this header, and the errors it answers with name it in `source.header`, so a client told to send the
 * header is told the name the API reads.
 */
final class IdempotencyHeader
{
    public const string DEFAULT = 'Idempotency-Key';

    public static function name(Repository $config): string
    {
        $header = $config->get('http-api.idempotency.header');

        return is_string($header) && $header !== '' ? $header : self::DEFAULT;
    }
}
