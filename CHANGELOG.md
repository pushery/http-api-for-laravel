# Changelog

All notable changes to `pushery/http-api-for-laravel` are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

A release carries only the sections that have an entry, in this order: `### ⚠️ Breaking`, `### ✨ Added`, `### 🔧 Changed`, `### ⚡ Performance`, `### 🐛 Fixed`, `### 🔒 Security`, `### 🧹 Deprecated`, `### 🗑️ Removed` and `### 📚 Documentation`.

## [0.2.0] - 2026-11-11

### ✨ Added

- **An API on a host of its own gets problem documents without a path prefix.** `http-api.problems.hosts` names the hosts whose every request belongs to the API, such as `api.example.com`, so a failure there is answered as `application/problem+json` without a prefix and without the request asking for JSON. An application whose routes know their API better binds its own `RecognizesApiRequests`, which the package then asks instead of the prefixes and the hosts.

### 🔧 Changed

- **The two switches read their rule from the package, so a fix to it reaches a published config.** `config/http-api.php` spelled out how a value of the `.env` turns them on or off, and a host that published the file kept that rule as it was on the day it published it, so a later fix to the rule, such as the one for an empty `.env` line, never reached the copy. The config calls `EnvSwitch::read()` now, and the rule ships in `src/Support/EnvSwitch.php`: `false`, `0`, `no` and `off` switch off, `true`, `1`, `yes` and `on` switch on, trimmed and in any case, and anything else keeps the value the package ships. **Upgrade:** in a published copy, replace the expression of each once with its call and import the class: `enabled` with `EnvSwitch::read(env('HTTP_API_ENABLED'), true)` and `problems.enabled` with `EnvSwitch::read(env('HTTP_API_PROBLEMS'), true)`, and `use Pushery\HttpApi\Support\EnvSwitch;` at the top.

### 🐛 Fixed

- **An empty `HTTP_API_ENABLED=` or `HTTP_API_PROBLEMS=` keeps the switch on.** An empty line, as copying a template of a `.env` leaves one, switched the package or its problem documents off without a word, while a missing line kept them on. Only `false`, `0`, `no` and `off` switch them off now, in any case and with spaces around them.

## [0.1.0] - 2026-11-08

### ✨ Added

- **`Idempotent`, the `Idempotency-Key` of draft-ietf-httpapi-idempotency-key-header for Laravel.** A write under a key acts once: a repeated request gets the first answer again with `Idempotent-Replayed: true`, the same key with another request is refused with `422`, a request while the first one runs with `409`, and a missing or malformed key on `POST` and `PATCH` with `400`. The request's fingerprint covers its method, path, query, body, form fields and files. Only an answer below `400` is kept, so a corrected request goes through under the same key. A key belongs to the scope `ResolvesIdempotencyScope` names, the signed-in user by default, and is taken for `in_flight_ttl` while its first request runs and kept for `ttl` afterwards. An answer the store cannot hold, and a streamed answer or a file download, still reaches the client, and its key stays used for `ttl`: a repeat is answered with `409` instead of acting a second time.
- **Problem documents, RFC 9457.** `Problem` answers as `application/problem+json`, and `ProblemRenderer` turns the failures of an API request into one: a validation into `422` with its `errors`, an unauthenticated request into `401` with the `WWW-Authenticate` header RFC 9110 requires, an HTTP exception into its status and headers with the message it was thrown with as `detail`, an exception implementing `ProvidesProblem` into its own document, and anything else into a `500` that says nothing about itself. A response an exception carries is kept as it is. A page that is no API page is left as Laravel renders it.

[Unreleased]: https://github.com/pushery/http-api-for-laravel/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/pushery/http-api-for-laravel/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/pushery/http-api-for-laravel/releases/tag/v0.1.0
