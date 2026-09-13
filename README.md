# Laravel Document Extraction

Extract text and structured data from your documents using a fluent, Laravel-native API.

Laravel Document Extraction works with PDFs, images, uploads, Laravel Storage, streams, and strings. It can read existing text, use Laravel AI for OCR, and return data that matches a schema you define.

## Installation

Install the current beta with Composer:

```sh
composer require "jkudish/laravel-document-extraction:^0.1@beta"
```

The package requires:

- PHP 8.4 or 8.5
- Laravel 13.23 or newer
- Linux with Imagick, Poppler, and util-linux
- PHP's Fileinfo, Imagick, Mbstring, and XMLReader extensions

You may publish the configuration and check your server's native tools:

```sh
php artisan vendor:publish --tag=document-extraction-config
php artisan extraction:doctor
```

`extraction:doctor` is an Artisan command. It checks your environment without contacting an AI provider or uploading a document.

## Basic usage

Start with a path, Laravel Storage file, upload, stream, or string:

```php
use Jkudish\DocumentExtraction\Facades\Extraction;

Extraction::fromPath($path);
Extraction::fromStorage($path, disk: 'documents');
Extraction::fromUpload($uploadedFile);
Extraction::fromStream($stream, mimeType: 'text/plain');
Extraction::fromString($contents, mimeType: 'application/json');
```

Each method returns a pending extraction. Finish it with `text()` or `extract()`.

### Extract text

```php
$result = Extraction::fromStorage('inbox/letter.pdf')->text();

$text = $result->text;
```

Existing text is read directly. Pages that need OCR use your configured Laravel AI provider.

To disable AI and return only existing text:

```php
$result = Extraction::fromPath($path)
    ->withoutAi()
    ->text();
```

### Extract structured data

Define the result with Laravel's JSON schema types:

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;

$result = Extraction::fromStorage('inbox/invoice.pdf')
    ->instructions('Extract only facts present in the invoice.')
    ->schema(fn (JsonSchema $schema) => [
        'invoice_number' => $schema->string()->nullable()->required(),
        'total' => $schema->string()->nullable()->required(),
    ])
    ->extract();

$invoice = $result->data;
```

You can also use a reusable Laravel AI agent that implements `Agent` and `HasStructuredOutput`:

```php
$result = Extraction::fromPath($path)
    ->using(PurchaseDocumentAgent::class)
    ->extract();
```

### Detect documents within a PDF

Use `detectDocuments()` when one PDF may contain several documents:

```php
$result = Extraction::fromPath($path)
    ->detectDocuments()
    ->schema(fn (JsonSchema $schema) => [
        'reference' => $schema->string()->nullable()->required(),
    ])
    ->extract();

foreach ($result->documents as $document) {
    $data = $document->data;
    $pages = $document->pages;
}
```

Document detection has its own provider and model settings. Page assignments are checked locally. Missing, overlapping, or ambiguous pages are reported instead of guessed.

## Configuration

Set your default provider and model in `.env`:

```dotenv
EXTRACTION_PROVIDER=openai
EXTRACTION_MODEL=gpt-5
```

Override them for one extraction when needed:

```php
$result = Extraction::fromPath($path)->text(
    provider: 'openai',
    model: 'gpt-5',
);
```

Publish `config/extraction.php` to configure:

- OCR and document detection routes
- Timeouts and document limits
- Provider options and middleware

For the optional OpenRouter setup tested by this project, see the [model evaluation guide](docs/grouping-models.md#optional-tested-configuration).

## Supported formats

- Text: TXT, CSV, HTML, JSON, and XML
- Documents: PDF
- Images: JPEG, PNG, WebP, TIFF, HEIC, HEIF, BMP, AVIF, and single-frame GIF

Available image formats depend on your installed Imagick codecs. Run `php artisan extraction:doctor` in each environment.

The package:

- Validates JSON and XML before returning text
- Never executes HTML
- Rejects XML entities and document types
- Rejects encrypted or malformed PDFs
- Rejects animated non-TIFF images
- Preserves original PDF page numbers
- Checks configured size, page, pixel, attachment, output, attempt, and time limits

## Source safety

The package reads source bytes into a private, bounded snapshot when extraction begins.

- Media type comes from the bytes, not the filename
- Original files, Storage objects, uploads, and streams remain yours
- Caller streams are not rewound or closed
- Temporary snapshots are removed after success or failure
- Configuration is copied for each request and does not change Laravel's global configuration

Configured storage adapters, stream wrappers, and executables remain trusted application code. Package limits are not a sandbox for hostile extensions.

## AI and validation

- OCR runs once for each prepared page that needs it
- Structured extraction sends prepared pages directly to Laravel AI
- Provider output is checked against the same schema sent to the provider
- Invalid or truncated JSON is rejected, not repaired
- Laravel AI's native failover rules decide which provider failures advance to a fallback
- Invalid output, package limits, and programming errors do not trigger a model repair pass
- One timeout and one set of resource limits cover the whole extraction

Application agents keep their instructions, schema, provider options, attributes, and middleware. Agents with tools or saved conversation history are rejected before document content is sent.

Structured extraction requires a provider path that preserves the original JSON response. OpenAI Responses and Anthropic's native and structured-tool paths are covered by offline fixtures. The current Bedrock structured path is rejected because it cannot preserve that response; Bedrock text and OCR are still supported.

## Results

Every result includes:

- A stable source fingerprint, so you know which file was processed
- The original page numbers behind each result
- Clear errors and any successful partial results
- A record of each AI attempt
- Available cost estimates from [Laravel AI Pricing](https://github.com/jkudish/laravel-ai-pricing)
- Local validation that rejects invalid model output instead of quietly repairing it
- Successful pages and documents even when another part of the extraction fails

`ExtractionResult` provides collections for `documents`, `pages`, `calls`, and `errors`. It also includes source details, media type, page count, cost summary, and `complete()`.

In document-detection mode, inspect each item in `documents`. Top-level `data` and `text` stay `null` so the first document is never returned by accident.

### Pricing evidence

Call records keep the requested and effective provider and model, duration, outcome, usage, and available quote.

- Unknown usage or prices remain unknown, not zero
- Known totals use exact money values and stay separate by currency
- Estimates are not provider invoices
- Faked calls are marked as simulated and are not counted as live spend
- Your application must persist results when it needs durable records

## Handling errors

Expected page and document failures appear in `$result->errors`. Always check `$result->complete()` before treating an extraction as complete.

Source, configuration, preparation, and global AI limit failures throw an `ExtractionException`:

```php
use Jkudish\DocumentExtraction\Exceptions\ExtractionException;

try {
    $result = Extraction::fromPath($path)->text();
} catch (ExtractionException $exception) {
    $code = $exception->errorCode;
    $partial = $exception->partialResult;
}
```

`partialResult` contains safe work completed before the failure. It may include pages, documents, calls, costs, and errors. It does not mean the extraction completed.

## Testing

Fake the facade to avoid parsing files or contacting a provider:

```php
use Jkudish\DocumentExtraction\ExtractionInvocation;
use Jkudish\DocumentExtraction\Facades\Extraction;

Extraction::fake(fn (ExtractionInvocation $invocation) => $expectedResult);

$result = Extraction::fromPath('/not/read/by/the/fake.pdf')->text();

Extraction::assertCalled(fn (ExtractionInvocation $invocation) =>
    $invocation->source->reference() === '/not/read/by/the/fake.pdf'
);
```

The fake supports result sequences, callbacks, `assertCalled()`, and `assertNothingCalled()`. It also prevents unexpected calls after a configured sequence is exhausted.

## Application responsibilities

The package handles document preparation, extraction, validation, and result details. Your application decides:

- Who may process documents
- Where results are stored
- Whether work runs in a queue
- When to retry
- What happens with extracted data

Our test documents are a useful starting point, but every document set is different. Before relying on the package in production, test it with your own files, schemas, and provider setup, and review the extracted data appropriately for your application.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for development setup. Maintainer checks are in [`docs/verification.md`](docs/verification.md).

Report security issues privately using [`SECURITY.md`](SECURITY.md).

## License

Laravel Document Extraction is open-sourced software licensed under the [MIT license](LICENSE.md).
