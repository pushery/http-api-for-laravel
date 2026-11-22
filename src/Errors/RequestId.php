<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use Illuminate\Http\Request;

/**
 * The id of a request, which an error response names in `Request-Id` and in the `meta` of every error object.
 *
 * Behind the gateway it is the id the gateway assigned, sent along as `Frontline-Request-Id`, so the client, the
 * gateway's log and the application's error name the same request. Without the gateway, or with a value that is no
 * UUID in lower case, the request gets a new UUID of version 7. The id is kept on the request, so every error of one
 * request names the same one.
 */
final class RequestId
{
    /** The header the gateway sends the id in. */
    public const string HEADER = 'Frontline-Request-Id';

    private const string ATTRIBUTE = self::class;

    private const string PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public static function of(Request $request): string
    {
        $kept = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($kept)) {
            return $kept;
        }

        $sent = $request->headers->get(self::HEADER);
        $id = is_string($sent) && preg_match(self::PATTERN, $sent) === 1 ? $sent : self::generate();
        $request->attributes->set(self::ATTRIBUTE, $id);

        return $id;
    }

    /**
     * A new UUID of version 7 (RFC 9562, section 5.7), in lower case with hyphens: 48 bits of Unix time in
     * milliseconds, the version, 74 random bits and the variant.
     */
    public static function generate(): string
    {
        $bytes = substr(pack('J', (int) floor(microtime(true) * 1000)), 2).random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20)]);
    }
}
