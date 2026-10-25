# Changelog

All notable changes to `phattarachai/laravel-sap-proxy` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/laravel-sap-proxy/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

## 1.1.0 - 2026-10-26

### Added

- `SapUnavailableException` (extends `SapProxyException`), thrown when the proxy answers its flagged
  503 (`sap_unavailable: true`) because SAP rejected the login or could not be reached. It carries
  `reason` (`auth`|`connect`) and `retryAfter` (seconds, `null` when the proxy's breaker has latched).

### Changed

- A flagged 503 is no longer retried. An unflagged 502/503 from a web server in front of a restarting
  proxy is still retried with the `retry` backoff.
