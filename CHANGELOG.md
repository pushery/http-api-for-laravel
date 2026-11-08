# Changelog

All notable changes to `pushery/http-api-for-laravel` are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

A release carries only the sections that have an entry, in this order: `### ⚠️ Breaking`, `### ✨ Added`, `### 🔧 Changed`, `### ⚡ Performance`, `### 🐛 Fixed`, `### 🔒 Security`, `### 🧹 Deprecated`, `### 🗑️ Removed` and `### 📚 Documentation`.

## [0.1.0] - 2026-11-08

### ✨ Added

- **`Idempotent`, the `Idempotency-Key` of draft-ietf-httpapi-idempotency-key-header for Laravel.** A write under a key acts once: a repeated request gets the first answer again with `Idempotent-Replayed: true`, the same key with another request is refused with `422`, a request while the first one runs with `409`, and a missing or malformed key on `POST` and `PATCH` with `400`. The request's fingerprint covers its method, path, query, body, form fields and files. Only an answer below `400` is kept, so a corrected request goes through under the same key. A key belongs to the scope `ResolvesIdempotencyScope` names, the signed-in user by default, and is taken for `in_flight_ttl` while its first request runs and kept for `ttl` afterwards. An answer the store cannot hold, and a streamed answer or a file download, still reaches the client, and its key stays used for `ttl`: a repeat is answered with `409` instead of acting a second time.
- **Problem documents, RFC 9457.** `Problem` answers as `application/problem+json`, and `ProblemRenderer` turns the failures of an API request into one: a validation into `422` with its `errors`, an unauthenticated request into `401` with the `WWW-Authenticate` header RFC 9110 requires, an HTTP exception into its status and headers with the message it was thrown with as `detail`, an exception implementing `ProvidesProblem` into its own document, and anything else into a `500` that says nothing about itself. A response an exception carries is kept as it is. A page that is no API page is left as Laravel renders it.

[Unreleased]: https://github.com/pushery/http-api-for-laravel/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/pushery/http-api-for-laravel/releases/tag/v0.1.0
