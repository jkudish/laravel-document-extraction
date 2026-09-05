<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Exceptions\StrayExtractionException;
use Jkudish\DocumentExtraction\ExtractionInvocation;
use Jkudish\DocumentExtraction\Facades\Extraction;
use Jkudish\DocumentExtraction\Results\CallRecord;
use Jkudish\DocumentExtraction\Results\CostSummary;
use Jkudish\DocumentExtraction\Results\DocumentResult;
use Jkudish\DocumentExtraction\Results\EvidenceOrigin;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use Jkudish\DocumentExtraction\Source\SourceType;

function fakeTextResult(string $text): ExtractionResult
{
    return new ExtractionResult(
        documents: [new DocumentResult(text: $text)],
        sourceSha256: str_repeat('e', 64),
        mediaType: 'text/plain',
        cost: CostSummary::unavailable(),
    );
}

/** @param array<string, mixed> $data */
function fakeDataResult(array $data): ExtractionResult
{
    return new ExtractionResult(
        documents: [new DocumentResult(pages: [1], data: $data)],
        sourceSha256: str_repeat('f', 64),
        mediaType: 'application/pdf',
        pageCount: 1,
        calls: [new CallRecord('structured', 'succeeded', [1])],
        cost: CostSummary::unavailable(),
    );
}

it('bypasses all source parsing and records an inspectable pending invocation', function (): void {
    $fake = Extraction::fake([fakeDataResult(['total' => '12.34'])]);

    $result = Extraction::fromPath('/a/nonexistent/private/document.pdf')
        ->schema(fn (JsonSchema $schema) => ['total' => $schema->string()->required()])
        ->instructions('Read faithfully')
        ->extract('openai', 'model-a', 45);

    expect($result->data)->toBe(['total' => '12.34'])
        ->and($result->evidenceOrigin)->toBe(EvidenceOrigin::Simulated)
        ->and($result->calls->first()?->evidenceOrigin)->toBe(EvidenceOrigin::Simulated)
        ->and($result->cost->evidenceOrigin)->toBe(EvidenceOrigin::Simulated);
    $aliasedFake = app('document-extraction');
    assert($aliasedFake instanceof DocumentExtraction);
    expect(app(DocumentExtraction::class))->toBe($fake);
    expect($aliasedFake)->toBe($fake);

    Extraction::assertCalled(function (ExtractionInvocation $invocation): bool {
        return $invocation->source->type === SourceType::Path
            && $invocation->source->reference() === '/a/nonexistent/private/document.pdf'
            && $invocation->instructions === 'Read faithfully'
            && $invocation->provider === 'openai'
            && $invocation->model === 'model-a'
            && $invocation->timeout === 45;
    });
});

it('does not resolve or inspect configured agents in fake mode', function (): void {
    Extraction::fake([fakeDataResult(['safe' => true])]);

    $result = Extraction::fromString('not inspected')
        ->using(stdClass::class)
        ->extract();

    expect($result->data)->toBe(['safe' => true]);
    Extraction::assertCalled(fn (ExtractionInvocation $invocation): bool => $invocation->agent === stdClass::class);
});

it('supports callback results and preserves per-request configuration snapshots', function (): void {
    config()->set('extraction.provider', 'captured-provider');
    $pending = null;
    Extraction::fake(function (ExtractionInvocation $invocation): ExtractionResult {
        expect($invocation->configuration['provider'])->toBe('captured-provider');

        return fakeTextResult($invocation->source->type->value);
    });
    $pending = Extraction::fromString('not inspected');
    config()->set('extraction.provider', 'later-provider');

    $result = $pending->text();

    expect($result->text)->toBe('string')
        ->and($result->evidenceOrigin)->toBe(EvidenceOrigin::Simulated);
    Extraction::assertCalled(1);
});

it('prevents unconfigured stray fake invocations', function (): void {
    Extraction::fake();

    expect(fn () => Extraction::fromString('not inspected')->text())
        ->toThrow(StrayExtractionException::class);
});

it('asserts that no extraction was called and resets records when replaced', function (): void {
    $first = Extraction::fake([fakeTextResult('first')]);
    Extraction::fromString('first source')->text();
    $second = Extraction::fake([fakeTextResult('second')]);

    Extraction::assertNothingCalled();

    expect($first->recorded())->toHaveCount(1)
        ->and($second->recorded())->toBeEmpty();
});
