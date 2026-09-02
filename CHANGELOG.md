# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The major version tracks the API path version: a `1.x` release of this package
speaks `/v1`.

## [1.0.0] - 2026-09-02

Initial release, matching version 1.0.0 of the Solar Juice Partner API.

### Added

- `Client` with resource groups for catalogue, inventory, specials, shipping
  and orders, plus an unauthenticated `health()` check.
- Cursor pagination on every list endpoint, with an `autoPage()` generator that
  walks `next_cursor` and yields items one at a time.
- Automatic `Idempotency-Key` on order creation, generated as a UUID v4 when the
  caller does not supply one and returned on the result for logging.
- Cancellation of an order that operations have not yet keyed in, with an
  optional note recorded on the event.
- Conditional fetch of a single order: pass an ETag and a `304` comes back as a
  `notModified` result rather than an exception.
- Retries with exponential backoff and full jitter on 429, 502, 503, 504 and
  network failures, honouring `Retry-After` when the API sends it and capping
  the honoured value at 60 seconds so an edge proxy cannot park a request for an
  hour.
- An API key that stays out of `print_r`, `var_dump`, `var_export`, `serialize`
  and constructor stack traces, wrappable in `ApiKey` or supplied as a closure.
- One exception per documented error code, with the request id, HTTP status and
  error details attached, and a base exception for codes added after this
  release. A response with no error envelope still carries the code its status
  implies, except for the two statuses that cover two codes each.
- Rate limit, request id and price list version from the most recent response
  readable on the client.
- Pluggable `Transport` interface with a curl based default, so the package has
  no runtime dependencies and can be pointed at another HTTP client or a stub.

[1.0.0]: https://github.com/Solar-Juice/solarjuice-php/releases/tag/v1.0.0
