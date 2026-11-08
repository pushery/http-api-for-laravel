<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP API for Laravel
    |--------------------------------------------------------------------------
    | Configuration for the package. Published to config/http-api.php with
    | `php artisan vendor:publish --tag=http-api-config`.
    | Read environment values with env() here and nowhere else. Outside a config
    | file env() returns null the moment the host app caches its config, so the
    | value silently collapses to its default in production and nowhere else.
    */

    /*
    | Whether the package acts at all. Switched off, the idempotency middleware lets every
    | request through as if it were not there, and no failure is answered with a problem
    | document. A switch is read typed, never cast: env() turns only `true`, `false`,
    | `(true)`, `(false)`, `empty` and `null` into values, and a cast to bool makes `no`,
    | `off` and ` false` true. So the spellings that switch it off are named, trimmed and in
    | any case, and anything else keeps the value shipped here.
    */
    'enabled' => ! in_array(
        is_string($enabled = env('HTTP_API_ENABLED')) ? strtolower(trim($enabled)) : $enabled,
        [false, '', '0', 'false', 'no', 'off'],
        true,
    ),

    /*
    |--------------------------------------------------------------------------
    | Problem documents (RFC 9457)
    |--------------------------------------------------------------------------
    | A failure on a request that belongs to the API is answered as
    | `application/problem+json`: a request under one of the route prefixes, or one that
    | expects JSON. Every other request is answered as Laravel answers it, so an HTML page
    | stays an HTML page.
    |
    | `type_base` is the URI the package's own problem types start with. RFC 9457 asks for
    | a type a person can look up, and the default is the page that documents each one. A
    | problem without a type of its own is `about:blank`.
    */
    'problems' => [
        'enabled' => ! in_array(
            is_string($problems = env('HTTP_API_PROBLEMS')) ? strtolower(trim($problems)) : $problems,
            [false, '', '0', 'false', 'no', 'off'],
            true,
        ),
        'route_prefixes' => ['api'],
        'when_json_is_expected' => true,
        'type_base' => is_string($typeBase = env('HTTP_API_PROBLEM_TYPE_BASE')) && trim($typeBase) !== ''
            ? trim($typeBase)
            : 'https://docs.pushery.com/http-api-for-laravel/problems/',

        // The scheme a 401 names in its WWW-Authenticate header, which RFC 9110 requires of every 401. Bearer is
        // Sanctum's and most APIs'; null leaves the header to the application.
        'www_authenticate' => 'Bearer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency-Key (draft-ietf-httpapi-idempotency-key-header)
    |--------------------------------------------------------------------------
    | A write that carries an `Idempotency-Key` acts once, however often it is sent. The
    | key and the answer are kept in a cache store that keeps `add()` atomic: redis,
    | database, memcached, dynamodb or array. The file store does not, and two requests
    | under one key could then both act. `store` names it, or null for the default store.
    |
    | `ttl` is how long an answer is kept, in seconds, and outlasts every retry a client
    | makes. `in_flight_ttl` is how long a key stays taken while its first request runs,
    | so a process that dies mid-request frees it again rather than holding it for the
    | whole `ttl`. Both are read typed and held to a floor of one second, because a value
    | that is no number would otherwise end up as zero, which keeps nothing at all.
    |
    | `replayed_headers` are the headers a repeated request gets back beside the status
    | and the body. `Set-Cookie` is never one of them, whatever this list says.
    */
    'idempotency' => [
        'header' => 'Idempotency-Key',
        'store' => is_string($store = env('HTTP_API_IDEMPOTENCY_STORE')) && trim($store) !== '' ? trim($store) : null,
        'ttl' => is_numeric($ttl = env('HTTP_API_IDEMPOTENCY_TTL')) && (int) $ttl >= 1
            ? (int) $ttl
            : 86_400,
        'in_flight_ttl' => is_numeric($inFlight = env('HTTP_API_IDEMPOTENCY_IN_FLIGHT_TTL')) && (int) $inFlight >= 1
            ? (int) $inFlight
            : 600,
        'max_key_length' => is_numeric($longest = env('HTTP_API_IDEMPOTENCY_MAX_KEY_LENGTH')) && (int) $longest >= 1
            ? (int) $longest
            : 255,
        'replayed_headers' => ['Content-Type', 'Location', 'Content-Location', 'ETag', 'Last-Modified'],
    ],

];
