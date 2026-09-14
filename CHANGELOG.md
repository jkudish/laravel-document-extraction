# Changelog

All notable changes to Laravel Document Extraction are documented here.

## [Unreleased]

## [0.1.0] - 2026-09-14

The first stable release.

### Changed

- Reworked the README around installation, common examples, and the public API.
- Moved the benchmark-tested OpenRouter grouping setup into an optional model guide.
- Added concise contributor, security, verification, and release documentation.
- Updated the default OpenAI configuration example to GPT-5.6 Luna.

## [0.1.0-beta.1] - 2026-09-09

This first beta is intended for controlled integration in Laravel 13 applications. Consumer feedback
may change the API before a stable release.

### Added

- Five bounded source inputs for paths, Laravel Storage, uploads, caller-owned streams, and strings.
- Image, PDF, XML, JSON, HTML, and direct-text preparation with bounded native workers,
  original-source custody, page provenance, and truthful cleanup failures.
- Native Laravel AI OCR and schema extraction through inline schemas or application agents, with
  local lossless JSON validation and native failover behavior.
- Opt-in logical document grouping with ambiguity, unassigned-page, partial-result, and complete
  per-attempt pricing evidence.
- Laravel fakes, an offline readiness command, exact-SHA local verification, a synthetic grouping
  corpus, and guarded paid-evaluation tooling.
- Portable clean-consumer proofs for direct and partial text handling, exact-decimal structured data,
  and a reusable nested application agent.

### Evaluated configuration

- The frozen synthetic grouping evaluation completed 72 of 72 all-nine scorecards across three
  observations of eight fixtures for each finalist.
- Gemini 2.5 Flash was fastest and least expensive in that corpus. The documented optional order is
  Gemini 2.5 Flash, Luna, then Haiku.
- The package does not set a vendor or model default. Consumers own provider aliases, models,
  endpoints, privacy options, credentials, and fallback order.

### Beta boundaries

- Linux with util-linux, Poppler, Imagick, and the locally verified codecs is the supported native
  runtime. Each deployment must run `php artisan extraction:doctor`.
- Our test documents are a useful starting point, but every document set is different. Before relying
  on the package in production, test it with your own files, schemas, and provider setup, and review
  the extracted data appropriately for your application.
- Consumers own source authorization, URL fetching, persistence, queues, idempotency, business
  confirmation, monitoring, and durable cost storage.
- This synchronous package does not silently repair invalid model JSON, truncate documents, convert
  currencies, or treat unavailable usage and pricing as zero.

[Unreleased]: https://github.com/jkudish/laravel-document-extraction/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/jkudish/laravel-document-extraction/releases/tag/v0.1.0
[0.1.0-beta.1]: https://github.com/jkudish/laravel-document-extraction/releases/tag/v0.1.0-beta.1
