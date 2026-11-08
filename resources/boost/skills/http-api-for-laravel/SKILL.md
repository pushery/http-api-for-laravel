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
failure of a request under `/api`, or one that expects JSON, is answered as
`application/problem+json`.

### 2. Configure

Publish everything at once, or one group at a time:

```bash
php artisan vendor:publish --tag="http-api"
php artisan vendor:publish --tag="http-api-config"
php artisan vendor:publish --tag="http-api-lang"
```

Every option in `config/http-api.php` is documented inline. Set
`http-api.idempotency.store` to a cache store that keeps `add()` atomic, such as redis or
database, where the default store is the file store.

### 3. Apply the package

Give every route that writes the `Idempotent` middleware, after the middleware that authenticates it. `POST` and `PATCH` then need an
`Idempotency-Key`, `PUT` and `DELETE` take one, and `Idempotent::class.':optional'` takes one
without requiring it.

A key belongs to the signed-in user. On a route whose callers are no signed-in users, bind a
`ResolvesIdempotencyScope` that knows them, or every request with a key is answered with `500`.

An exception of the application that should reach a client with its own type, title and members
implements `ProvidesProblem`.

## Examples

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pushery\HttpApi\Idempotency\Idempotent;

Route::post('/api/orders', function (Request $request) {
    return response()->json(['ordered' => $request->input('item')], 201);
})->middleware(['auth:sanctum', Idempotent::class]);
```

```php
use Pushery\HttpApi\Problems\Problem;
use Pushery\HttpApi\Problems\ProvidesProblem;

final class OutOfStock extends RuntimeException implements ProvidesProblem
{
    public function toProblem(): Problem
    {
        return new Problem(409, type: 'https://example.com/problems/out-of-stock', title: 'Out of stock');
    }
}
```

## Anti-Patterns

- Keeping the keys in the file cache store: it does not make `add()` atomic, so two requests under
  one key can both act. Name a redis, database, memcached or dynamodb store instead.
- Keeping the keys in a Redis whose `maxmemory-policy` is anything but `noeviction`: an evicted key
  lets a retry act twice. Use a Redis instance with `noeviction`, or the database store.
- Giving the middleware to a route that anonymous callers write to, without a scope resolver: the
  package refuses to guess a caller from an address. Bind a `ResolvesIdempotencyScope`.
- Reusing one key for different requests in a client: the second request is refused with `422`.
  A key stands for one request and every retry of it.
- Building an error response by hand on an API route: throw, or return a `Problem` with
  `toResponse()`, so the shape stays the one every client reads.
