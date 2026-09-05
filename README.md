# Laravel Document Extraction

Laravel-native document text and schema extraction with provenance and AI cost tracking.

> [!IMPORTANT]
> This branch establishes the package and quality-tooling foundation. Public extraction behavior is
> implemented by subsequent tasks; no model requests or document processing are present yet.

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
verification commands.

## Configuration

The package provider is auto-discovered and publishes `config/extraction.php`. The foundation keeps
the accepted provider, model, purpose overrides, middleware/options, timeout, and bounded resource
limit keys stable for production extraction and benchmark integration.

## License

Laravel Document Extraction is open-sourced software licensed under the [MIT license](LICENSE.md).
