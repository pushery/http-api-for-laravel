<p align="center">
  <a href="https://github.com/pushery/http-api-for-laravel">
    <img src="https://raw.githubusercontent.com/pushery/http-api-for-laravel/main/art/header.png" alt="HTTP API for Laravel" width="100%">
  </a>
</p>

# HTTP API for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/http-api-for-laravel.svg)](https://packagist.org/packages/pushery/http-api-for-laravel)
[![PHP Version](https://img.shields.io/packagist/dependency-v/pushery/http-api-for-laravel/php.svg)](https://packagist.org/packages/pushery/http-api-for-laravel)
[![Laravel Versions](https://badge.laravel.cloud/badge/pushery/http-api-for-laravel?style=flat)](https://packagist.org/packages/pushery/http-api-for-laravel)
[![License](https://img.shields.io/packagist/l/pushery/http-api-for-laravel.svg)](LICENSE)

[![Tests: Pest 5](https://img.shields.io/badge/tests-Pest%205-8BC34A.svg)](https://pestphp.com)
![Coverage: 100%](https://img.shields.io/badge/coverage-100%25-brightgreen.svg)
![Type coverage: 100%](https://img.shields.io/badge/types-100%25-brightgreen.svg)
[![PHPStan: level 10](https://img.shields.io/badge/PHPStan-10-blue.svg)](https://phpstan.org)
[![Code style: Pint](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)

Idempotency-Key replays that compare the request body, and JSON:API errors from a catalog shared with the gateway, for every error of a Laravel API.

## Documentation

**Full documentation at
[docs.pushery.com/http-api-for-laravel](https://docs.pushery.com/http-api-for-laravel/).**
This README is the quick start; the guides and the configuration reference live there.

## Requirements

- PHP 8.4+
- Laravel 13+

The idempotency store on the database cache is tested on PostgreSQL and MySQL 8.4, the
databases Laravel Cloud runs (serverless Postgres and MySQL 8.4 LTS). The fast suite
runs on SQLite.

Tested on the floor of the declared PHP range, because a `>=` promise is only proven
by running on the boundary, and on the minor version above it, so the range above the
floor is exercised here rather than first by a consumer.

## Installation

```bash
composer require pushery/http-api-for-laravel
```

The service provider is registered automatically through package discovery.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=http-api-config
```

This writes `config/http-api.php`, where every option is documented inline.

Every setting and the environment variables that change it without publishing the file are listed in
the [configuration reference](https://docs.pushery.com/http-api-for-laravel/#configuration).

## Usage

### An Idempotency-Key on a write

In `routes/api.php`, where Laravel puts `/api` in front of every path:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pushery\HttpApi\Idempotency\Idempotent;

Route::post('/orders', function (Request $request) {
    return response()->json(['ordered' => $request->input('item')], 201);
})->middleware(['auth:sanctum', Idempotent::class]);
```

The first request under a key acts. A repeat gets the first answer back with
`Idempotent-Replayed: true`, the same key with another request `422`, a repeat while the first one
runs `409`, and a `POST` or `PATCH` without a key `400`. The middleware runs after authentication:
keys belong to the signed-in user unless you bind a `ResolvesIdempotencyScope` of your own. They live
in a cache store of their own that every process and server of the API shares and that loses nothing
before `ttl`: no eviction, no `cache:clear`, and a restart with persistence.
See [Idempotency](https://docs.pushery.com/http-api-for-laravel/idempotency).

### Conditional requests

```php
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Route;
use Pushery\HttpApi\Preconditions\Conditional;

Route::patch('/orders/{order}', function (Request $request, Order $order) {
    $order->update(['note' => $request->input('data.attributes.note')]);

    return new JsonApiResource($order);
})->middleware(['auth:sanctum', Conditional::class]);
```

Every answer that shows a model carries its version in `ETag`. A read whose `If-None-Match` names
it gets `304` without a body, a change whose `If-Match` names an older version `412`, and a change
without `If-Match` `428`. The comparison and the change run in one transaction that locks the
resource's row, so of two changes made against the same version the second is refused instead of
undoing the first. On a route with both middleware, `Idempotent` comes first.
See [Conditional requests](https://docs.pushery.com/http-api-for-laravel/conditional-requests/).

### Pagination

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

The list pages with `page[cursor]` and `page[size]`, `20` items without a size and `100` at most,
and its `links.next` and `links.prev` keep every other parameter of the request. A size outside
that range and a cursor that is not one of the list's are refused with `400`, naming the parameter
in `source.parameter`. The model's key is ordered by last, so rows with the same sort values are
neither skipped nor repeated between pages. A list that filters, sorts and includes through
`spatie/laravel-query-builder` gets its refusals of an undeclared parameter answered the same way.
See [Pagination](https://docs.pushery.com/http-api-for-laravel/pagination/).

### Errors

Nothing to set up: every failure of a request to the API host, `api.` before the host of
`app.url`, is answered as JSON:API error objects, `application/vnd.api+json`, with a status, a
stable code, a title and a detail from the error catalog the gateway in front of the application
answers from, so the same error looks the same from either. A request that expects JSON on any
route keeps Laravel's own JSON and its `message` unless `http-api.errors.when_json_is_expected` is
`true`. An API on another host names it in `HTTP_API_HOST`, further hosts go into
`http-api.errors.hosts`, an API that still answers under `/api` names that prefix in
`http-api.errors.route_prefixes`, and an application that knows its API requests another way binds
`RecognizesApiRequests`. An exception of your own implements `ProvidesErrorDocument` to answer with
a code of the catalog and the input it points to, a cause of your product is a detail you declare
under a code in `http-api.errors.details`, and `NegotiateJsonApi` holds a route to the
content negotiation of JSON:API. See [Errors](https://docs.pushery.com/http-api-for-laravel/errors/).

## Testing

The test suite is not part of the published package: the tests and their PHPUnit
configuration stay in the development repository, so a clone of this repository has no
`composer test`. [CONTRIBUTING.md](https://github.com/pushery/http-api-for-laravel/blob/main/CONTRIBUTING.md) says how a change is tested on its way in.

## Security

Please review the [security policy](SECURITY.md) and report vulnerabilities
privately rather than opening a public issue.

## Built by PUSHERY

This package is built and maintained by [PUSHERY](https://www.pushery.com) — a
Berlin-based studio building Laravel applications, SaaS products, and open-source
tools.

Building a Laravel UI? [WireKit](https://www.wirekit.app) is PUSHERY's open-source
Livewire component kit.
Browse the rest of our work at [pushery.com](https://www.pushery.com).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
