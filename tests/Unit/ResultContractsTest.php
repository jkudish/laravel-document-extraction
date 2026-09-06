<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Jkudish\DocumentExtraction\Results\CallRecord;
use Jkudish\DocumentExtraction\Results\CostSummary;
use Jkudish\DocumentExtraction\Results\DocumentResult;
use Jkudish\DocumentExtraction\Results\EvidenceOrigin;
use Jkudish\DocumentExtraction\Results\ExtractionError;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use Jkudish\DocumentExtraction\Results\PageResult;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Laravel\Ai\Responses\Data\Usage;

it('derives ordinary data and text from its one document without duplicate mutable values', function (): void {
    $documents = collect([new DocumentResult(data: [])]);
    $result = new ExtractionResult(
        documents: $documents,
        sourceSha256: str_repeat('a', 64),
        mediaType: 'application/json',
        cost: CostSummary::unavailable(),
    );

    expect($result->data)->toBe([])
        ->and($result->text)->toBeNull();

    $result->documents->put(0, new DocumentResult(text: 'replacement'));

    expect($result->data)->toBeNull()
        ->and($result->text)->toBe('replacement');
});

it('never truncates detection mode to the first detected document', function (): void {
    $result = new ExtractionResult(
        documents: [
            new DocumentResult(pages: [1], data: ['invoice' => 1]),
            new DocumentResult(pages: [2], data: ['invoice' => 2]),
        ],
        sourceSha256: str_repeat('b', 64),
        mediaType: 'application/pdf',
        pageCount: 2,
        detectionMode: true,
        cost: CostSummary::unavailable(),
    );

    expect($result->data)->toBeNull()
        ->and($result->text)->toBeNull()
        ->and($result->documents)->toHaveCount(2)
        ->and($result->documents->pluck('data')->all())->toBe([
            ['invoice' => 1],
            ['invoice' => 2],
        ]);
});

it('preserves null empty and failed structured outcomes distinctly', function (): void {
    $failure = new ExtractionError('invalid_output', 'Structured output did not match the requested schema.', retryable: false);
    $empty = new DocumentResult(data: []);
    $blank = new DocumentResult(text: '');
    $failed = new DocumentResult(complete: false, error: $failure);

    expect($empty->data)->toBe([])
        ->and($empty->complete())->toBeTrue()
        ->and($blank->text)->toBe('')
        ->and($blank->complete())->toBeTrue()
        ->and($failed->data)->toBeNull()
        ->and($failed->complete())->toBeFalse()
        ->and($failed->error)->toBe($failure);
});

it('uses Laravel collections and computes completion from requested coverage', function (): void {
    $error = new ExtractionError('provider_failed', 'The provider could not complete the request.', [2], retryable: true);
    $result = new ExtractionResult(
        documents: [new DocumentResult(pages: [1], text: 'page one')],
        pages: [new PageResult(1, 'page one'), new PageResult(2, complete: false, error: $error)],
        calls: [new CallRecord('ocr', 'failed', [2])],
        errors: [$error],
        sourceSha256: str_repeat('c', 64),
        mediaType: 'application/pdf',
        pageCount: 2,
        coverageComplete: true,
        cost: CostSummary::unavailable(),
    );

    expect($result->documents::class)->toBe(Collection::class)
        ->and($result->pages::class)->toBe(Collection::class)
        ->and($result->calls::class)->toBe(Collection::class)
        ->and($result->errors::class)->toBe(Collection::class)
        ->and($result->complete())->toBeFalse();
});

it('serializes only stable safe error fields', function (): void {
    $error = new ExtractionError(
        code: 'invalid_output',
        message: 'The response did not match the schema.',
        pages: [4, 2],
        path: '$.total',
        retryable: false,
    );

    expect($error->toArray())->toBe([
        'code' => 'invalid_output',
        'message' => 'The response did not match the schema.',
        'pages' => [2, 4],
        'path' => '$.total',
        'retryable' => false,
    ]);
});

it('marks copied fake results as simulated evidence', function (): void {
    $live = new ExtractionResult(
        documents: [new DocumentResult(text: 'text')],
        sourceSha256: str_repeat('d', 64),
        mediaType: 'text/plain',
        cost: CostSummary::unavailable(),
    );
    $simulated = $live->asSimulated();

    expect($live->evidenceOrigin)->toBe(EvidenceOrigin::Live)
        ->and($simulated->evidenceOrigin)->toBe(EvidenceOrigin::Simulated)
        ->and($simulated->cost->evidenceOrigin)->toBe(EvidenceOrigin::Simulated)
        ->and($simulated->text)->toBe('text');
});

it('distinguishes replayed evidence from new spend and strips pricing from public fakes', function (): void {
    $quote = new CostQuote(
        new Money('0.123456789012345678', 'USD'),
        CostCompleteness::Complete,
        PricingSource::Configured,
    );
    $quotedMoney = $quote->cost ?? throw new LogicException('The test quote requires a subtotal.');
    $call = new CallRecord(
        stage: 'extraction',
        outcome: 'succeeded',
        provider: 'requested-provider',
        model: 'requested-model',
        cost: $quote,
        extractionInvocationId: 'extraction-id',
        nativeInvocationId: 'native-id',
        ordinal: 1,
        requestedProvider: 'requested-provider',
        requestedModel: 'requested-model',
        effectiveProvider: 'effective-provider',
        effectiveModel: 'effective-model',
        usage: new Usage(2, 3),
        startedAt: new DateTimeImmutable('2026-09-06T00:00:00+00:00'),
    );
    $live = new ExtractionResult(
        documents: [new DocumentResult(data: ['value' => 'safe'])],
        sourceSha256: str_repeat('d', 64),
        mediaType: 'application/json',
        calls: [$call],
        cost: new CostSummary(['USD' => $quotedMoney], complete: true),
    );

    $recorded = $live->asRecorded();
    $simulated = $live->asSimulated();

    expect($recorded->evidenceOrigin)->toBe(EvidenceOrigin::Recorded)
        ->and($recorded->calls->first()?->evidenceOrigin)->toBe(EvidenceOrigin::Recorded)
        ->and($recorded->calls->first()?->cost)->toBe($quote)
        ->and($recorded->cost->knownByCurrency->get('USD'))->toBe($quote->cost)
        ->and($recorded->cost->evidenceOrigin)->toBe(EvidenceOrigin::Recorded)
        ->and($simulated->evidenceOrigin)->toBe(EvidenceOrigin::Simulated)
        ->and($simulated->calls->first()?->cost)->toBeNull()
        ->and($simulated->calls->first()?->usage)->toBeNull()
        ->and($simulated->cost->knownByCurrency)->toBeEmpty()
        ->and($simulated->cost->unpricedCalls->all())->toBe(['extraction-id:1'])
        ->and($simulated->cost->complete)->toBeFalse();
});

it('rejects ambiguous or impossible page provenance', function (Closure $make): void {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'empty document pages' => [fn () => new DocumentResult(pages: [])],
    'complete document without output' => [fn () => new DocumentResult],
    'complete page without text' => [fn () => new PageResult(1)],
    'pages on unpaginated source' => [fn () => new ExtractionResult(
        documents: [new DocumentResult(pages: [1], text: 'invented')],
        sourceSha256: str_repeat('e', 64),
        mediaType: 'text/plain',
        cost: CostSummary::unavailable(),
    )],
    'missing paginated document provenance' => [fn () => new ExtractionResult(
        documents: [new DocumentResult(text: 'missing')],
        sourceSha256: str_repeat('e', 64),
        mediaType: 'application/pdf',
        pageCount: 1,
        cost: CostSummary::unavailable(),
    )],
    'document page beyond source' => [fn () => new ExtractionResult(
        documents: [new DocumentResult(pages: [2], text: 'impossible')],
        sourceSha256: str_repeat('e', 64),
        mediaType: 'application/pdf',
        pageCount: 1,
        cost: CostSummary::unavailable(),
    )],
    'error page beyond source' => [fn () => new ExtractionResult(
        documents: [new DocumentResult(pages: [1], complete: false)],
        sourceSha256: str_repeat('e', 64),
        mediaType: 'application/pdf',
        pageCount: 1,
        errors: [new ExtractionError('failed', 'Processing failed.', [2])],
        cost: CostSummary::unavailable(),
    )],
    'duplicate physical pages' => [fn () => new ExtractionResult(
        documents: [new DocumentResult(pages: [1], text: 'duplicate')],
        sourceSha256: str_repeat('e', 64),
        mediaType: 'application/pdf',
        pageCount: 1,
        pages: [new PageResult(1, 'one'), new PageResult(1, 'again')],
        cost: CostSummary::unavailable(),
    )],
]);

it('does not retain structured data on a technical failure', function (): void {
    $error = new ExtractionError('invalid_output', 'Structured output was invalid.');

    expect(fn () => new DocumentResult(data: ['unsafe' => true], complete: false, error: $error))
        ->toThrow(InvalidArgumentException::class);
});

it('reports empty detection as incomplete without fabricating a document', function (): void {
    $result = new ExtractionResult(
        documents: [],
        sourceSha256: str_repeat('a', 64),
        mediaType: 'application/pdf',
        pageCount: 1,
        detectionMode: true,
    );

    expect($result->complete())->toBeFalse()
        ->and($result->documents)->toBeEmpty()
        ->and($result->data)->toBeNull();
});

it('rejects overlapping original pages across detected documents', function (): void {
    expect(fn () => new ExtractionResult(
        documents: [
            new DocumentResult(pages: [1, 2], text: 'first'),
            new DocumentResult(pages: [2], text: 'second'),
        ],
        sourceSha256: str_repeat('a', 64),
        mediaType: 'application/pdf',
        pageCount: 2,
        detectionMode: true,
    ))->toThrow(InvalidArgumentException::class, 'overlap');
});

it('consolidates document and page errors without duplicating the same failure', function (): void {
    $documentError = new ExtractionError('provider_failed', 'Document processing failed.', [1]);
    $pageError = new ExtractionError('provider_failed', 'Page processing failed.', [2]);
    $result = new ExtractionResult(
        documents: [new DocumentResult(pages: [1, 2], complete: false, error: $documentError)],
        pages: [new PageResult(2, complete: false, error: $pageError)],
        errors: [$documentError],
        sourceSha256: str_repeat('a', 64),
        mediaType: 'application/pdf',
        pageCount: 2,
    );

    expect($result->errors->all())->toBe([$documentError, $pageError])
        ->and($result->complete())->toBeFalse();
});

it('validates nested error provenance against the original source', function (string $owner): void {
    $error = new ExtractionError('provider_failed', 'Processing failed.', [2]);

    expect(fn () => new ExtractionResult(
        documents: [new DocumentResult(
            pages: [1],
            complete: false,
            error: $owner === 'document' ? $error : null,
        )],
        pages: $owner === 'page' ? [new PageResult(1, complete: false, error: $error)] : [],
        sourceSha256: str_repeat('a', 64),
        mediaType: 'application/pdf',
        pageCount: 1,
    ))->toThrow(InvalidArgumentException::class, 'source page count');
})->with(['document', 'page']);
