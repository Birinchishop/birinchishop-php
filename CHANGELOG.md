# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).
While the version is `0.x`, minor releases may contain small breaking changes; they will always be listed here.

## [0.1.0] - 2026-11-19

First public (beta) release, API version `2026-10-23`.

### Added

- `Birinchishop` client for every REST endpoint of the Partner API v1: account, offers, links, orders,
  sale chance, stats, events, AI insights, Meta ads (campaigns, pause/resume/budget, actions), webhooks and reference.
- Test mode (sandbox) support: `isTestMode()` for `bsk_test_` keys and `setTestOrderStatus()`
  (`POST /sandbox/orders/{order_id}/status`).
- Automatic retries with exponential backoff and jitter for `429 rate_limited`, `5xx` and network errors
  (GET requests and `createOrder`), honouring `Retry-After` / `RateLimit-Reset` (capped at 60 s).
- `Idempotency-Key` on every `createOrder` call (UUID v4 unless you pass your own), kept across retries;
  replays are reported as `replayed => true`.
- Pagination generators: `iterOffers()`, `iterLinks()`, `iterOrders()`, `iterEvents()` and `paginate()`.
- Typed exceptions (`BirinchishopException` and subclasses) carrying HTTP status, API error code, details,
  request id and `Retry-After`.
- `Webhook::verifySignature()` / `Webhook::constructEvent()` (HMAC-SHA256, constant-time, 300 s tolerance)
  and `TrackingUrl::fill()`.
- Pluggable `HttpTransport` interface; default `CurlTransport` (ext-curl). No other runtime dependencies.

[0.1.0]: https://github.com/Birinchishop/birinchishop-php/releases/tag/v0.1.0
