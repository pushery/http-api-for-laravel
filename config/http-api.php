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
    | request through as if it were not there, and no failure is answered with a problem
    | document. A switch is read typed, never cast: env() turns only `true`, `false`,
    | `(true)`, `(false)`, `empty` and `null` into values, and a cast to bool makes `no`,
    | `off` and ` false` true. So the spellings that switch it off are named, trimmed and in
    | any case, and anything else keeps the value shipped here. An empty line, as copying a
    | template of a .env leaves one, keeps it too, as a missing line does.
    */
    'enabled' => EnvSwitch::read(env('HTTP_API_ENABLED'), true),

    /*
    |--------------------------------------------------------------------------
    | Problem documents (RFC 9457)
    |--------------------------------------------------------------------------
    | A failure on a request that belongs to the API is answered as
    | `application/problem+json`: a request under one of the route prefixes or to one of
    | the hosts, or one that expects JSON. Every other request is answered as Laravel
    | answers it, so an HTML page stays an HTML page.
    |
    | `type_base` is the URI the package's own problem types start with. RFC 9457 asks for
    | a type a person can look up, and the default is the page that documents each one. A
    | problem without a type of its own is `about:blank`.
    */
    'problems' => [
        'enabled' => EnvSwitch::read(env('HTTP_API_PROBLEMS'), true),
        'route_prefixes' => ['api'],

        // The hosts whose every request belongs to the API, for an API that answers on a host of its own without a path
        // prefix, such as `api.example.com`. Matched exactly, in any case. An application that knows its API better binds
        // its own Pushery\HttpApi\Problems\RecognizesApiRequests, which then decides instead of the prefixes and these.
        'hosts' => [],

        // A request that expects JSON gets a problem document on every route, outside the API too, in place of Laravel's
        // own JSON and its `message`. False keeps Laravel's answer there, and only the prefixes, the hosts and an Accept
        // header that asks for application/problem+json decide.
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
    | problem the middleware answers with names this header too. `max_key_length` is the
    | longest key it takes, in characters; a longer one is refused with `400`, and so is
    | one with a character outside printable ASCII.
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
