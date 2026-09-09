# Laravel Document Extraction

Laravel-native document text and schema extraction with provenance and AI cost tracking.

> [!IMPORTANT]
> Bounded direct-text, PDF, and image preparation, native OCR, and schema-model execution are
> implemented with per-attempt Laravel AI Pricing evidence. Opt-in page grouping is implemented;
> benchmark integration and production hardening remain later package stages. Unavailable usage or pricing stays explicitly
> unpriced rather than being reported as zero-cost.

## Foundation

- PHP 8.4 and 8.5; Laravel 13.23 and the current Laravel 13 release
- Laravel AI SDK and Laravel AI Pricing
- native Illuminate Image with Intervention Image 4's Imagick driver
- Spatie PDF-to-text, Poppler, util-linux `prlimit`, and Opis JSON Schema 2
- Orchestra Testbench, native Pest 5 with local TIA, PAO, Pint, and Larastan level 10
- no database, queues, frontend, hosted CI, provider credentials, or live data

## Setup

On Debian 12 or in an Amp orb, the reproducible setup installs both supported PHP versions,
Imagick, PCOV, Poppler, and signature-verified Composer before installing the lockfile:

```sh
.agents/setup
.agents/resume
```

The setup script uses the signed Sury PHP repository and is idempotent. Verify production
preparation readiness without a provider or network request:

```sh
php artisan extraction:doctor
```

See
[`docs/verification.md`](docs/verification.md) for full, TIA, PAO, and four-cell compatibility
verification commands, plus the local exact-SHA PR receipt and signoff protocol.

For a clean distribution/consumer proof, run:

```sh
composer test:consumer
```

This builds the Composer archive, verifies that runtime code, configuration, prompts, schemas,
workers, license, and public documentation are present, then installs that archive into a disposable
Testbench application. The consumer tests cover ReceiptFox-style exact-decimal extraction, jMail-style
direct and partial text with caller-owned streams, and a third nested shipping-manifest schema through
an application agent. They use native Laravel AI fakes and make no provider request.

The archive intentionally excludes repository-only tooling and evidence: `.agents`, `.amp`, tests,
scripts, quality configuration, caches, private evaluation artifacts, and `composer.lock`. The lockfile
remains tracked in this repository so package development and verification use a reproducible graph;
library consumers correctly resolve dependencies against their own application lockfile.

## Pull request verification

`composer pr:check` runs the complete uncached package plan without GitHub/provider credentials and
publishes a private exact-SHA receipt only after the candidate remains clean and stable. A separate
`composer pr:signoff -- --approved-sha FULL_SHA` command requires explicit approval, a matching open
PR, and a dedicated status-only GitHub token before an unforced signoff and exact-SHA readback. This
local self-attestation is intentionally not hosted CI, cryptographic signing, merge authority, or a
release action. See [`docs/verification.md`](docs/verification.md#exact-sha-pull-request-workflow).

## Configuration

The package provider is auto-discovered and publishes `config/extraction.php`. Provider, model, and
timeout resolution is terminal call > purpose (`ocr`) > package root > native application-agent
method/attribute > Laravel AI default. The pending request snapshots these values and never mutates
Laravel's global configuration. A configured provider array is passed to Laravel AI's native
failover unchanged; models belong in its provider map and cannot be combined with a separate model.

The package intentionally ships without a vendor or model default. The current development evidence
recommends Gemini 2.5 Flash for document detection, then Luna and Haiku. An application can adopt or
replace that order entirely through published configuration. Because all three evaluated models use
OpenRouter, define distinct Laravel AI provider aliases so each attempt keeps its own endpoint
options:

```php
// config/ai.php
'providers' => [
    // ...
    'grouping-gemini' => [
        'driver' => 'openrouter',
        'key' => env('OPENROUTER_API_KEY'),
    ],
    'grouping-luna' => [
        'driver' => 'openrouter',
        'key' => env('OPENROUTER_API_KEY'),
    ],
    'grouping-haiku' => [
        'driver' => 'openrouter',
        'key' => env('OPENROUTER_API_KEY'),
    ],
],
```

```php
// config/extraction.php
'detection' => [
    'provider' => [
        'grouping-gemini' => env('EXTRACTION_GROUPING_GEMINI_MODEL', 'google/gemini-2.5-flash'),
        'grouping-luna' => env('EXTRACTION_GROUPING_LUNA_MODEL', 'openai/gpt-5.6-luna'),
        'grouping-haiku' => env('EXTRACTION_GROUPING_HAIKU_MODEL', 'anthropic/claude-haiku-4.5'),
    ],
    'model' => null,
    'timeout' => null,
    'options' => [
        'grouping-gemini' => [
            'max_tokens' => 512,
            'reasoning' => ['effort' => 'none', 'exclude' => true],
            'provider' => [
                'only' => [env('EXTRACTION_GROUPING_GEMINI_ENDPOINT', 'google-vertex/global')],
                'allow_fallbacks' => false,
                'require_parameters' => true,
                'data_collection' => 'deny',
                'zdr' => true,
            ],
        ],
        'grouping-luna' => [
            'max_completion_tokens' => 512,
            'reasoning' => ['effort' => 'none', 'exclude' => true],
            'provider' => [
                'only' => [env('EXTRACTION_GROUPING_LUNA_ENDPOINT', 'azure/eu')],
                'allow_fallbacks' => false,
                'require_parameters' => true,
                'data_collection' => 'deny',
                'zdr' => true,
            ],
        ],
        'grouping-haiku' => [
            'max_tokens' => 512,
            'reasoning' => ['effort' => 'none', 'exclude' => true],
            'provider' => [
                'only' => [env('EXTRACTION_GROUPING_HAIKU_ENDPOINT', 'amazon-bedrock/global')],
                'allow_fallbacks' => false,
                'require_parameters' => true,
                'data_collection' => 'deny',
                'zdr' => true,
            ],
        ],
    ],
],
```

Laravel AI advances through that map only for its native failoverable provider failures. Invalid
JSON/schema output, package limits, and programming errors remain observable failures rather than
silently changing models. Consumers can reorder, replace, or remove aliases and models without
changing package code. The evaluated routes, evidence limits, and volatile pricing caveats are in
[`docs/grouping-models.md`](docs/grouping-models.md).

Package-owned OCR and inline-schema agents read `extraction.options` and purpose-specific options,
keyed by provider name, and `extraction.middleware`. A purpose provider's option array replaces its
root provider option array rather than being deep-merged. An application agent supplied through
`using()` owns its native `providerOptions()` and `middleware()` behavior; package options are not
injected through a parallel mechanism that Laravel AI does not expose.

Preparation defaults are 100 MB per source, 100 physical pages, 50 million decoded pixels per
rendered page/frame, 30 seconds per parser operation, 600 seconds for the complete invocation, 120
seconds per AI attempt, 128 AI attempts, 10 MB retained UTF-8 output, and 512 MB active
package-owned source/derivative storage. Native work runs in a sanitized isolated PHP worker with a
PHP memory limit, Linux address-space/file-size limits, and absolute argv-array paths. The separate
20 MB inline-attachment limit is enforced before base64 encoding for each AI request; it is not a
source-admission or image-resize rule. Model/provider request and context limits may be lower and
fail explicitly—there is no silent truncation, generic arbitrary-schema chunking, or strategy-changing
downsampling.

`prlimit --fsize` limits each native-created file, while the package checks aggregate owned bytes
between incremental operations. For a hard cumulative cap on scratch files created *during* a
native operation, run the application with a dedicated filesystem quota or equivalent container /
systemd disk control. Linux with util-linux is the supported and tested containment runtime;
Windows and macOS support is not claimed.

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

Snapshots are made owner-read-only after capture. Stream reads use nonblocking mode and bounded
readiness waits; the borrowed stream's blocking mode is restored afterward. Streams that cannot
support nonblocking reads or readiness waits fail explicitly. Application-owned storage adapters
must also bound their connection/open operations: the invocation checks its deadline before and
after opening, but cannot preempt arbitrary PHP adapter code. Configured executables and PHP stream
wrappers are trusted application code; these resource controls are not a sandbox for hostile code.

Configure a pending request with `schema()`, `using()`, `instructions()`, `detectDocuments()`,
`pages()`, or `withoutAi()`, then call `text()` or `extract()`. Terminal provider/model/timeout
arguments follow Laravel AI's native `Lab|array|string|null` routing shape. Invalid or conflicting
configuration is rejected before source bytes are read. Configuration is copied when the pending
request is created and never mutates Laravel's global configuration.

## Native OCR and structured extraction

OCR runs once per prepared visual page and preserves original page provenance. A failed provider
call produces an explicit incomplete `PageResult` while successful page text remains available.
Structured extraction sends prepared visual PDF/image pages directly to a native Laravel AI agent;
it does not require an OCR-then-schema pass. Text-like inputs provide their bounded normalized text.

With `detectDocuments()`, paginated input first goes to a native structured detector using the
independently configurable `extraction.detection` route and provider options. The package validates
the returned original-page assignments locally, then processes disjoint unambiguous groups
sequentially with the same supplied schema or application agent. Missing, ambiguous, duplicate,
overlapping, and out-of-selection assignments remain explicit incomplete evidence; they never cause
a fallback whole-source extraction. A structurally valid grouping is not a claim that the model's
semantic classification is factually correct.

Detection, grouped extraction, and grouped OCR share one invocation deadline, AI-attempt budget,
retained-output budget, attachment limit, call sequence, and cost summary. Operational failure in
one usable group retains successful siblings; global resource and configuration failures stop with
accumulated partial evidence. Detection requires paginated input and enough attachment capacity for
the complete selected-page context—there is no silent cropping, windowing, or parser-specific split.
When an invocation combines live and simulated native agents, each call retains its own evidence
mode and pricing eligibility while the result and cost summary serialize their aggregate mode as
`mixed`; converting that result to recorded evidence preserves live quotes and simulated call labels.

Inline schemas use Laravel's native `JsonSchema` types:

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;

$result = Extraction::fromPath($path)
    ->instructions('Extract only facts present in the invoice.')
    ->schema(fn (JsonSchema $schema) => [
        'invoice' => $schema->string()->nullable()->required(),
        'total' => $schema->string()->nullable()->required(),
        'lines' => $schema->array()->items(
            $schema->object([
                'description' => $schema->string()->required(),
                'amount' => $schema->string()->required(),
            ])
        )->required(),
    ])
    ->extract(provider: 'openai', model: 'gpt-5');

$result->data;
```

Reusable extraction agents are ordinary application agents implementing `Agent` and
`HasStructuredOutput`:

```php
$result = Extraction::fromPath($path)
    ->using(PurchaseDocumentAgent::class)
    ->extract();
```

The actual resolved application agent is invoked, preserving its instructions, schema, attributes,
provider options, and ordinary middleware. Agents with nonempty tools or saved conversation history
are rejected before source egress; those capabilities are not silently stripped. Extraction-agent
middleware must not directly call `prompt()` on that same agent object before forwarding the outer
extraction invocation. This is this package's supported boundary—not a general Laravel prohibition—and
the package does not claim every such violation can be detected before the nested call reaches a
provider. Nested calls on another agent, and same-agent calls after forwarding returns, are supported
and remain unattributed to extraction. Middleware may also return a cached response without forwarding;
that response's JSON text is validated without inventing a provider call.

Post-forward middleware can edit `text` or the native `structured` array. Explicitly changed JSON text
takes precedence if both are changed. Structured-only edits are validated against the same schema;
strictly unchanged values at existing paths retain their already-validated original JSON container
shapes. New empty objects need `new stdClass`; `[]` at a new path remains a list. To change an existing
empty object's representation to an empty list, update JSON text explicitly: normalized PHP `[]`
cannot express that difference. This preserves existing data representations, not array-element
identity through reordering, and never repairs invalid original provider JSON.

For every attempt, the package compiles the exact native schema passed to the provider, rejects an
invalid schema or external reference before dispatch, then validates the returned step text against
that same Opis draft 2020-12 schema before converting it to a PHP array. Malformed/truncated JSON,
provider-declared incomplete responses, wrong types, missing required values, additional properties,
and root lists fail locally. This does not trust Laravel AI's normalized structured `[]` as proof that
raw JSON was valid, so valid empty
objects remain distinguishable from lists when the provider path preserves response text.

The schema contract starts at Laravel's native types, not arbitrary JSON Schema input. Unsupported
keywords discarded by Laravel's `JsonSchema::fromArray()` cannot be recovered or enforced here;
use native type methods (for example, a single-value enum rather than an unsupported `const`).
Provider-specific native wire adaptations, such as Anthropic's schema sanitizer, do not weaken
local validation against the original compiled native schema.

OpenAI Responses, Anthropic native structured output, and Anthropic synthetic structured-tool HTTP
paths are covered with offline protocol fixtures. For Anthropic structured tools, the package reads
the bounded original HTTP JSON to preserve object/list identity before Laravel AI's normalization.
The current Bedrock structured gateway exposes no equivalent lossless response, so structured
extraction rejects that provider before dispatch; native Bedrock OCR/text is not excluded by this
structured-output restriction. Other providers require a JSON-text-preserving native gateway;
these fixtures are not a live-provider or every-provider compatibility claim.

Provider options are read once per attempt. Metadata and ordinary provider tuning are preserved,
but options cannot override generated messages, schemas, tools, routing, or conversation state.
Final post-middleware local/inline image attachments are frozen and checked against the pre-base64
byte limit before dispatch. Remote or otherwise unbounded middleware attachments are rejected.

Only Laravel AI exceptions implementing its native failover contract advance to a configured
fallback. Invalid JSON/schema output, package budget/limit failures, and programming errors do not
retry or invoke a model-based repair pass. The shared deadline includes snapshotting, preparation,
every fallback attempt, and response middleware; each provider timeout is clamped to the remaining
invocation time, and late returns are rejected. Global AI limits throw `AiExecutionException`.
If work already produced safe page/call evidence, AI-limit and configuration exceptions retain it
in `partialResult` while processing stops.

## Preparation behavior and formats

- TXT, CSV, HTML, JSON, and XML are returned deterministically as bounded UTF-8 text with line
  endings normalized. JSON and XML hints do not bypass syntax validation. HTML is never rendered or
  executed; XML document types/entities are rejected and external resolution is disabled.
- PDF metadata and encryption are gated by `pdfinfo` before text or rendering. Existing text is read
  one original page at a time through Spatie PDF-to-text, preserving selected-page and blank-page
  alignment. Pages with absent/invalid text or any embedded raster inventory require OCR; zero text
  plus zero images is still treated conservatively because vector-only content can be visible.
- JPEG, PNG, WebP, TIFF (frames become pages), HEIC, HEIF, BMP, AVIF, and single-frame GIF are
  accepted when the installed Imagick codecs can decode them. Animated/multi-frame non-TIFF inputs
  are rejected. Orientation and final PNG normalization use Illuminate Image's Imagick driver.
  Laravel 13.23's Image API does not directly admit TIFF, HEIC, HEIF, or AVIF input, so a bounded
  direct Imagick step selects a frame and converts it to an admitted lossless representation before
  orientation and final normalization continue through Illuminate Image.

`text()` avoids a model when bounded direct text is sufficient. `withoutAi()` returns all available
PDF text and explicit `ocr_required` page coverage for anything unprocessed; it never invents blank
or complete pages. The decoded-pixel limit is checked before an image decode or PDF render. It does
not reject a large-geometry PDF when only its bounded text layer is read. Structured extraction
prepares normalized visual pages and invokes native schema extraction without a mandatory OCR pass.
Document detection remains a downstream package stage.

## Result contract

`ExtractionResult` exposes Laravel collections for `documents`, `pages`, `calls`, and `errors`, plus
source identity, normalized media type, nullable page count, cost summary, and `complete()`. In
ordinary mode, `data` and `text` are read-only accessors derived from the single `DocumentResult`.
In document-detection mode they remain `null`; callers inspect every document instead of silently
receiving the first group. Failed structured documents retain no unvalidated data, and unpaginated
content never receives invented page numbers.

Each dispatched native attempt has a stable extraction invocation ID, native invocation ID, ordinal,
stage and pages, requested/resolved/effective provider-model identities, start time, duration, outcome,
nullable usage, and the original Laravel AI Pricing `CostQuote`. Effective identity and usage remain
`null` when the provider does not establish them; requested identity is never substituted to make a
quote possible. A returned response is recorded and priced once even if later local finish-reason,
deadline, byte-limit, JSON, or schema validation rejects it. Failed attempts without a response remain
unpriced with unknown usage and spend. Laravel AI's all-zero default usage is also treated as unknown,
not proof of a measured zero.

`CostSummary` combines known quote subtotals exactly with `Money::plus`, independently by currency,
without floating-point arithmetic or FX conversion. Partial quotes keep their known subtotal and full
quote provenance while their stable call reference remains in `unpricedCalls`; unavailable quotes and
unknown failed spend are listed there too. `complete` therefore means every live call has measured usage
and a complete quote. No-AI and provider-short-circuit paths have no calls and complete zero spend.
These are calculated pricing estimates from the pinned catalog or configured rates, not provider
invoices.

Evidence mode distinguishes newly incurred `live` calls from historical `recorded` results and
`simulated` native or public extraction fakes. Simulated calls are never counted as measured live spend,
and aggregate native `AgentResponse` usage is not priced again over its individual step responses.
Attempt evidence is accumulated in memory; an OS kill or a provider call whose response is lost before
the process receives it can leave spend unknown or lose the in-memory record. Applications that require
durability must persist completed extraction results in their own storage.

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
