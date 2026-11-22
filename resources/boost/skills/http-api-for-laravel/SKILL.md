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

Make the application's API writes idempotent with the `Idempotency-Key` header, hold every change
of a resource to the version the client read with `ETag` and `If-Match`, page its lists with a
cursor, and answer its API failures as JSON:API errors from the error catalog the gateway shares,
in the smallest correct way.

## Workflow

### 1. Install

```bash
composer require pushery/http-api-for-laravel
```

The service provider is registered automatically through package discovery. From then on every
failure of a request to the API host, `api.` before the host of `app.url` or the host in
`HTTP_API_HOST`, is answered as JSON:API error objects, `application/vnd.api+json`, with the
status, code, title and detail of the error catalog. A request that expects JSON on any route keeps
Laravel's own JSON and its `message`; set `http-api.errors.when_json_is_expected` to `true` to
answer it with JSON:API errors outside the API too.

### 2. Configure

Publish everything at once, or one group at a time:

```bash
php artisan vendor:publish --tag="http-api"
php artisan vendor:publish --tag="http-api-config"
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

Give the routes of a resource that clients change the `Conditional` middleware. Every answer that
shows a model then carries its tag in `ETag`, a read whose `If-None-Match` names it gets `304`, a
`PATCH`, `PUT` or `DELETE` whose `If-Match` names an older version gets `412`, and one without
`If-Match` gets `428`. The middleware reads the resource from the route's parameters, so the route
binds it by route model binding. A model whose version is one column implements
`HasEntityVersion`, and its tag follows that column instead of every column of its row.

A route that answers with a list hands its query and the request to `CursorPages`, resolved from
the container, and the page to `JsonApiResource::collection()`. The list then pages with
`page[cursor]` and `page[size]`, `http-api.pagination.size` items without a size and
`http-api.pagination.max_size` at most, and refuses a size or a cursor it cannot take with `400`.
A list that filters, sorts or includes declares what it takes with `spatie/laravel-query-builder`,
which the application requires, and hands `->getEloquentBuilder()` to `CursorPages`. A filter,
sort, include, field or append the route does not declare is then answered with `400` and the
parameter in `source.parameter`.

An exception of the application that should reach a client with a code of the catalog and the
input it points to implements `ProvidesErrorDocument`. A route that speaks JSON:API gets the
`NegotiateJsonApi` middleware, which refuses a body in another media type with `415` and an
`Accept` header that admits no JSON:API answer with `406`.

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

A change of a resource behind both middleware, `Idempotent` first, so a retry gets the first answer
and its tag back:

```php
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Route;
use Pushery\HttpApi\Idempotency\Idempotent;
use Pushery\HttpApi\Preconditions\Conditional;

Route::patch('/orders/{order}', function (Request $request, Order $order) {
    $order->update(['note' => $request->input('data.attributes.note')]);

    return new JsonApiResource($order);
})->middleware(['auth:sanctum', Idempotent::class, Conditional::class]);
```

A list that pages with a cursor, newest first:

```php
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Route;
use Pushery\HttpApi\Pagination\CursorPages;

Route::get('/orders', function (Request $request, CursorPages $pages) {
    return JsonApiResource::collection($pages->of(Order::query()->latest(), $request));
})->middleware('auth:sanctum');
```

```php
namespace App\Exceptions;

use Pushery\HttpApi\Errors\ErrorDocument;
use Pushery\HttpApi\Errors\JsonApiError;
use Pushery\HttpApi\Errors\ProvidesErrorDocument;
use RuntimeException;

final class OutOfStock extends RuntimeException implements ProvidesErrorDocument
{
    public function toErrorDocument(): ErrorDocument
    {
        return ErrorDocument::of(JsonApiError::of('conflict', source: ['pointer' => '/data/attributes/quantity']));
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
- Giving a route `Conditional` before `Idempotent`: a retry of a change that already acted reaches
  the comparison first and is refused with `412`, because the first attempt changed the resource.
  List `Idempotent` first.
- Giving `Conditional` to a route that changes a resource it does not bind by route model binding,
  or ordering it before `SubstituteBindings`: the change has nothing to be compared against, and the
  middleware answers `500` and reports it rather than letting the change run unchecked.
- Comparing `If-Match` in the route or setting `ETag` by hand: a comparison outside the transaction
  that runs the change lets two changes made against the same version both through.
- Answering a list with `->get()`, or paging it with `paginate()` and page numbers: the answer grows
  with the table, and a row added between two pages shifts every page after it. Page with
  `CursorPages`.
- Reading `filter`, `sort` or `include` straight from the request in a route: every column and
  every relation becomes reachable, and an unknown name is ignored instead of refused. Declare them
  with `spatie/laravel-query-builder`, two levels of includes at most.
- Sorting a paged list by an expression with `orderByRaw()`: a cursor holds the values of the
  columns the list is sorted by, so sort by columns.
- Building an error response by hand on an API route: throw, so the catalog writes the error and
  the shape stays the one every client and the gateway read. A code the catalog lacks belongs in
  the catalog, never in an answer of its own.
- Branching a client on the `title` or the `detail` of an error: they are for people, and the
  `code` is the stable part.
