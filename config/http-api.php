<?php

declare(strict_types=1);

use Pushery\HttpApi\Support\EnvSwitch;

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
    | request through as if it were not there, and no failure is answered with JSON:API
    | errors. A switch is read typed, never cast: env() turns only `true`, `false`,
    | `(true)`, `(false)`, `empty` and `null` into values, and a cast to bool makes `no`,
    | `off` and ` false` true. So the spellings that switch it off are named, trimmed and in
    | any case, and anything else keeps the value shipped here. An empty line, as copying a
    | template of a .env leaves one, keeps it too, as a missing line does.
    */
    'enabled' => EnvSwitch::read(env('HTTP_API_ENABLED'), true),

    /*
    |--------------------------------------------------------------------------
    | Errors (JSON:API 1.1)
    |--------------------------------------------------------------------------
    | A failure on a request that belongs to the API is answered as JSON:API errors,
    | `application/vnd.api+json`, from the error catalog the package ships: the same codes,
    | titles, details and headers the gateway in front of the application answers with. A
    | request belongs to the API when it goes to the API host, to one of the hosts or under
    | one of the route prefixes, or when it asks for `application/vnd.api+json`. The API
    | host is `api.` before the host of `app.url`, so an application at `example.com` has
    | its API at `api.example.com`, and no path prefix is shipped. Every other request is
    | answered as Laravel answers it, so an HTML page stays an HTML page.
    */
    'errors' => [
        'enabled' => EnvSwitch::read(env('HTTP_API_ERRORS'), true),
        'route_prefixes' => [],

        // The host the API answers on. Unset, it is `api.` before the host of `app.url`, without a leading `www.`, and the
        // host of `app.url` itself where that starts with `api.`. HTTP_API_HOST names another, `developers.example.com`
        // for instance. Matched exactly, in any case.
        'api_host' => is_string($apiHost = env('HTTP_API_HOST')) && trim($apiHost) !== '' ? trim($apiHost) : null,

        // The hosts whose every request belongs to the API, for an API that answers on more hosts than one. Matched
        // exactly, in any case. An application that knows its API better binds its own
        // Pushery\HttpApi\Errors\RecognizesApiRequests, which then decides instead of the API host, these and the prefixes.
        'hosts' => [],

        // True answers a request that expects JSON with JSON:API errors on every route, outside the API too, in place of
        // Laravel's own JSON and its `message`. False keeps Laravel's answer there.
        'when_json_is_expected' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency-Key (draft-ietf-httpapi-idempotency-key-header)
    |--------------------------------------------------------------------------
    | A write that carries an `Idempotency-Key` acts once, however often it is sent. The
    | key and the answer are kept in a cache store that every process and server of the
    | API shares and that outlasts the request: redis, database or dynamodb. The file
    | store qualifies only where a single server answers the API, because each server
    | keeps its own disk, and the array store forgets its keys with the request, so it
    | serves tests alone. Name a single store, never one of the `failover` driver: when
    | one of its stores throws, it takes the keys in the next, which never saw the keys
    | taken before, so a repeat acts a second time, and once it reaches array in the
    | chain, every repeat does. `store` names it, or null for the default store.
    |
    | The store has to lose nothing before `ttl`, or a retry acts a second time. A key goes
    | early in three ways. The store evicts it under memory pressure: Redis under every
    | `maxmemory-policy` but `noeviction`, memcached unless it runs with `-M`. A flush
    | empties it: `cache:clear` and `optimize:clear` flush the default store, a Redis
    | store flushes the whole database of its connection and a database store its table.
    | Or the store restarts without persistence: Redis without `appendonly yes`, memcached
    | every time. So give the keys a store of their own, a Redis connection and database
    | no other store uses or a database store with a table of its own, and leave it out
    | of every `cache:clear` a deploy runs. A database store deletes the row of an
    | expired key only when the key is read again, and a file store its file, so on
    | either store schedule the pruning the package ships once a day, in routes/console.php:
    | Schedule::call(\Pushery\HttpApi\Idempotency\PruneExpiredIdempotencyKeys::class)->daily().
    |
    | `header` is the name of the header a client sends its key in, `Idempotency-Key` as
    | the draft names it. An API that calls it something else names it here, and every
    | error the middleware answers with names this header in `source.header`. `max_key_length` is the
    | longest key it takes, in characters; a longer one is refused with `400`, and so is
    | one with a character outside printable ASCII.
    |
    | `ttl` is how long an answer is kept, in seconds, and outlasts every retry a client
    | makes; the record of a first request that never answers is kept as long, so another
    | request under its key stays refused. `in_flight_ttl` is how long that first request
    | may run before a retry of the same request takes its key over. Both are read typed
    | and held to a floor of one second, because a value that is no number would
    | otherwise end up as zero, which keeps nothing at all.
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

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    | A list that pages with CursorPages gives `size` items a page where the request names
    | no `page[size]`, and refuses a `page[size]` above `max_size` with `400`, so no single
    | request reads a page without a bound. Both are read typed and held to a floor of one,
    | as the numbers of the idempotency settings are.
    */
    'pagination' => [
        'size' => is_numeric($size = env('HTTP_API_PAGE_SIZE')) && (int) $size >= 1
            ? (int) $size
            : 20,
        'max_size' => is_numeric($largest = env('HTTP_API_MAX_PAGE_SIZE')) && (int) $largest >= 1
            ? (int) $largest
            : 100,
    ],

];
