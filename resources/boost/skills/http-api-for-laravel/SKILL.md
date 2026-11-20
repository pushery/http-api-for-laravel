---
name: http-api-for-laravel
description: >
  Install, configure, and apply the HTTP API for Laravel package in a Laravel
  application.
license: MIT
metadata:
  author: pushery
---

# HTTP API for Laravel

Use this skill when a Laravel application installs or integrates the
`pushery/http-api-for-laravel` package. The full reference is at
<https://docs.pushery.com/http-api-for-laravel/>.

## Primary Goal

Make the application's API writes idempotent with the `Idempotency-Key` header, and answer its
API failures as RFC 9457 problem documents, in the smallest correct way.

## Workflow

### 1. Install

```bash
composer require pushery/http-api-for-laravel
```

The service provider is registered automatically through package discovery. From then on every
failure of a request under `/api`, or of one that expects JSON on any route, is answered as
`application/problem+json` in place of Laravel's own JSON and its `message`; set
`http-api.problems.when_json_is_expected` to `false` to keep Laravel's answer on the routes outside
the API, such as a form the application's own scripts send as JSON.

### 2. Configure

Publish everything at once, or one group at a time:

```bash
php artisan vendor:publish --tag="http-api"
php artisan vendor:publish --tag="http-api-config"
php artisan vendor:publish --tag="http-api-lang"
```

Every option in `config/http-api.php` is documented inline. Set
`http-api.idempotency.store` to a cache store of the keys' own that every process and server of the
API shares and that loses nothing before `ttl`: a redis connection and database no other store
uses, with `noeviction` and `appendonly yes`, or a database store with a table of its own. Leave the
default store to the application's cache, because `cache:clear` and `optimize:clear`, which a
deploy often runs, flush it.

On a database or a file store, schedule the pruning of expired keys once a day, in
`routes/console.php`, or the rows or files of past keys stay for good:

```php
use Illuminate\Support\Facades\Schedule;
use Pushery\HttpApi\Idempotency\PruneExpiredIdempotencyKeys;

Schedule::call(PruneExpiredIdempotencyKeys::class)->daily();
```

### 3. Apply the package

Give every route that writes the `Idempotent` middleware, after the middleware that authenticates it. `POST` and `PATCH` then need an
`Idempotency-Key`, `PUT` and `DELETE` take one, and `Idempotent::class.':optional'` takes one
without requiring it.

A key belongs to the signed-in user. On a route whose callers are no signed-in users, bind a
`ResolvesIdempotencyScope` that knows them, in the `register` method of a service provider, or every
request with a key is answered with `500`.

An exception of the application that should reach a client with its own type, title and members
implements `ProvidesProblem`.

## Examples

A route in `routes/api.php`, where Laravel puts `/api` in front of every path, so it answers
`/api/orders`:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pushery\HttpApi\Idempotency\Idempotent;

Route::post('/orders', function (Request $request) {
    return response()->json(['ordered' => $request->input('item')], 201);
})->middleware(['auth:sanctum', Idempotent::class]);
```

```php
use Pushery\HttpApi\Problems\Problem;
use Pushery\HttpApi\Problems\ProvidesProblem;
use RuntimeException;

final class OutOfStock extends RuntimeException implements ProvidesProblem
{
    public function toProblem(): Problem
    {
        return new Problem(409, type: 'https://example.com/problems/out-of-stock', title: 'Out of stock');
    }
}
```

## Anti-Patterns

- Keeping the keys in the array cache store, or in the file store while more than one server answers
  the API: a repeat that reaches another process or server finds no key and acts a second time.
  Name a store every process and server of the API shares: redis, database or dynamodb.
- Keeping the keys in a store of the `failover` driver, as a default store often is: when one of its
  stores throws, a Redis that cannot be reached for one, the keys go to the next, which never saw
  the keys taken before, and a repeat acts a second time; once the chain reaches `array`, every
  repeat does. Name a single store in `http-api.idempotency.store`.
- Keeping the keys in a store that evicts: a Redis whose `maxmemory-policy` is anything but
  `noeviction`, or memcached without `-M`. An evicted key lets a retry act twice. Use a Redis
  instance with `noeviction`, or the database store.
- Running `cache:clear` or `optimize:clear` on the store of the keys, or keeping the keys in the
  default store or in a Redis database another store shares, which those commands flush: every key
  goes before its `ttl`. Give the keys a store of their own, and leave it out of every `cache:clear`
  a deploy runs.
- Keeping the keys in a Redis without `appendonly yes`, or in memcached: a restart loses every key.
- Giving the middleware to a route whose answer carries a secret the client sees only once, such as
  a new API token or recovery codes: the answer lies unencrypted in the cache store for `ttl`, where
  whoever reads the store reads the secret. Leave such a route without the middleware, or guard the
  store like the database.
- Giving the middleware to a route that anonymous callers write to, without a scope resolver: the
  package refuses to guess a caller from an address. Bind a `ResolvesIdempotencyScope`.
- Reusing one key for different requests in a client: the second request is refused with `422`.
  A key stands for one request and every retry of it.
- Building an error response by hand on an API route: throw, or return a `Problem` with
  `toResponse()`, so the shape stays the one every client reads.
