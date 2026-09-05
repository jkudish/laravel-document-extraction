# Laravel Document Extraction

Laravel-native document text and schema extraction with provenance and AI cost tracking.

> [!IMPORTANT]
> The source, pending-request, result, and testing-fake contracts are available. Live PDF/image/text
> parsing and AI processing are not implemented yet: calling a terminal operation on the live
> binding fails explicitly with `ProcessingUnavailableException` after safe source snapshotting.

## Foundation

- PHP 8.4 and 8.5; Laravel 13.23 and the current Laravel 13 release
- Laravel AI SDK and Laravel AI Pricing
- native Illuminate Image with Intervention Image 4's Imagick driver
- Spatie PDF-to-text and Poppler, plus Opis JSON Schema 2
- Orchestra Testbench, native Pest 5 with local TIA, PAO, Pint, and Larastan level 10
- no database, queues, frontend, hosted CI, provider credentials, or live data

## Setup

On Debian 12 or in an Amp orb, the reproducible setup installs both supported PHP versions,
Imagick, PCOV, Poppler, and signature-verified Composer before installing the lockfile:

```sh
.agents/setup
.agents/resume
```

The setup script uses the signed Sury PHP repository and is idempotent. See
[`docs/verification.md`](docs/verification.md) for full, TIA, PAO, and four-cell compatibility
verification commands, plus the local exact-SHA PR receipt and signoff protocol.

## Pull request verification

`composer pr:check` runs the complete uncached package plan without GitHub/provider credentials and
publishes a private exact-SHA receipt only after the candidate remains clean and stable. A separate
`composer pr:signoff -- --approved-sha FULL_SHA` command requires explicit approval, a matching open
PR, and a dedicated status-only GitHub token before an unforced signoff and exact-SHA readback. This
local self-attestation is intentionally not hosted CI, cryptographic signing, merge authority, or a
release action. See [`docs/verification.md`](docs/verification.md#exact-sha-pull-request-workflow).

## Configuration

The package provider is auto-discovered and publishes `config/extraction.php`. The foundation keeps
the accepted provider, model, purpose overrides, middleware/options, timeout, and bounded resource
limit keys stable for production extraction and benchmark integration.

## Request and source contracts

All five source methods return a `PendingExtraction`:

```php
Extraction::fromPath($path);
Extraction::fromStorage($path, disk: 'documents');
Extraction::fromUpload($uploadedFile);
Extraction::fromStream($stream, mimeType: 'text/plain');
Extraction::fromString($contents, mimeType: 'application/json');
```

At terminal execution, the live binding reads the actual bytes into a bounded private snapshot,
computes the source SHA-256 and size while streaming, and detects the media type from bytes. Filename
extensions, upload metadata, and MIME hints do not override byte inspection. Caller streams are read
from their current position to EOF and are never rewound or closed. Original paths, uploads, storage
objects, and caller streams remain caller-owned; package snapshots are removed on success and error.

Configure a pending request with `schema()`, `using()`, `instructions()`, `detectDocuments()`,
`pages()`, or `withoutAi()`, then call `text()` or `extract()`. Terminal provider/model/timeout
arguments follow Laravel AI's native `Lab|array|string|null` routing shape. Invalid or conflicting
configuration is rejected before source bytes are read. Configuration is copied when the pending
request is created and never mutates Laravel's global configuration.

## Result contract

`ExtractionResult` exposes Laravel collections for `documents`, `pages`, `calls`, and `errors`, plus
source identity, normalized media type, nullable page count, cost summary, and `complete()`. In
ordinary mode, `data` and `text` are read-only accessors derived from the single `DocumentResult`.
In document-detection mode they remain `null`; callers inspect every document instead of silently
receiving the first group. Failed structured documents retain no unvalidated data, and unpaginated
content never receives invented page numbers.

## Testing with the facade fake

Consumer tests can bypass all source parsing and provider activity with a result sequence or callback:

```php
use Jkudish\DocumentExtraction\ExtractionInvocation;
use Jkudish\DocumentExtraction\Facades\Extraction;

Extraction::fake(fn (ExtractionInvocation $invocation) => $expectedResult);

$result = Extraction::fromPath('/not/read/by/the/fake.pdf')
    ->withoutAi()
    ->text();

Extraction::assertCalled(fn (ExtractionInvocation $invocation) =>
    $invocation->source->reference() === '/not/read/by/the/fake.pdf'
);
```

`Extraction::fake()` prevents stray invocations when its configured sequence is exhausted, supports
`assertCalled()` and `assertNothingCalled()`, and marks returned results, call records, and cost
summaries as simulated evidence so they cannot be mistaken for live provider activity.

## License

Laravel Document Extraction is open-sourced software licensed under the [MIT license](LICENSE.md).
