<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Http;
use Jkudish\DocumentExtraction\AI\DocumentDetectionAgent;
use Jkudish\DocumentExtraction\AI\InlineSchemaAgent;
use Jkudish\DocumentExtraction\AI\OcrAgent;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Exceptions\AiExecutionException;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Jkudish\DocumentExtraction\Results\CallRecord;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;

final class GroupingPromptPrefix
{
    public bool $handled = false;

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $this->handled = true;

        return $next($prompt->prepend('grouping-middleware'));
    }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('extraction.provider', 'openai');
    config()->set('extraction.model', 'extraction-model');
});

function groupingPdf(): string
{
    return __DIR__.'/../Fixtures/Pdf/multipage.pdf';
}

/** @return array<string, Type> */
function groupingSchema(JsonSchema $schema): array
{
    return ['value' => $schema->string()->required()];
}

it('keeps detection off by default with no detector call or cost', function (): void {
    DocumentDetectionAgent::fake([['groups' => [['pages' => [1], 'ambiguous' => false]]]])
        ->preventStrayPrompts();
    InlineSchemaAgent::fake([['value' => 'ordinary']])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromPath(groupingPdf())
        ->pages([1])
        ->schema(groupingSchema(...))
        ->extract();

    expect($result->data)->toBe(['value' => 'ordinary'])
        ->and($result->detectionMode)->toBeFalse()
        ->and($result->calls)->toHaveCount(1);
    DocumentDetectionAgent::assertNeverPrompted();
});

it('rejects unpaginated detection before any model call', function (): void {
    DocumentDetectionAgent::fake([['groups' => []]])->preventStrayPrompts();

    expect(fn () => app(DocumentExtraction::class)
        ->fromString('two logical documents', 'text/plain')
        ->detectDocuments()
        ->text())
        ->toThrow(ConfigurationException::class, 'paginated');

    DocumentDetectionAgent::assertNeverPrompted();
});

it('groups selected original PDF pages and extracts each group through one shared session', function (): void {
    $sourceHash = hash_file('sha256', groupingPdf());
    $before = glob(sys_get_temp_dir().'/laravel-document-extraction-*') ?: [];
    config()->set('extraction.detection', [
        'provider' => 'openrouter',
        'model' => 'detector-model',
        'timeout' => 17,
        'options' => ['openrouter' => ['route' => 'vision']],
    ]);
    $middleware = new GroupingPromptPrefix;
    config()->set('extraction.middleware', [$middleware]);
    DocumentDetectionAgent::fake([
        ['groups' => [
            ['pages' => [2], 'ambiguous' => false],
            ['pages' => [4], 'ambiguous' => false],
        ]],
    ])->preventStrayPrompts();
    InlineSchemaAgent::fake([
        ['value' => 'document-two'],
        ['value' => 'document-four'],
    ])->preventStrayPrompts();

    $pending = app(DocumentExtraction::class)
        ->fromPath(groupingPdf())
        ->pages([4, 2])
        ->detectDocuments()
        ->schema(groupingSchema(...));
    config()->set('extraction.detection.provider', 'changed-after-request');
    $result = $pending->extract(provider: 'anthropic', model: 'extractor-model');

    expect($result->detectionMode)->toBeTrue()
        ->and($result->data)->toBeNull()
        ->and($result->text)->toBeNull()
        ->and($result->documents->pluck('data')->all())->toBe([
            ['value' => 'document-two'],
            ['value' => 'document-four'],
        ])
        ->and($result->documents->map(fn ($document) => $document->pages?->all())->all())->toBe([[2], [4]])
        ->and($result->calls->pluck('stage')->all())->toBe(['detection', 'extraction', 'extraction'])
        ->and($result->calls->map(fn (CallRecord $call): array => $call->pages->all())->all())->toBe([[2, 4], [2], [4]])
        ->and($result->calls->pluck('ordinal')->all())->toBe([1, 2, 3])
        ->and($result->calls->pluck('extractionInvocationId')->unique())->toHaveCount(1)
        ->and($result->complete())->toBeTrue()
        ->and($result->evidenceOrigin->value)->toBe('simulated')
        ->and($middleware->handled)->toBeTrue()
        ->and(hash_file('sha256', groupingPdf()))->toBe($sourceHash)
        ->and(glob(sys_get_temp_dir().'/laravel-document-extraction-*') ?: [])->toBe($before);

    DocumentDetectionAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        assert($prompt->agent instanceof DocumentDetectionAgent);

        return $prompt->provider->name() === 'openrouter'
            && $prompt->model === 'detector-model'
            && $prompt->timeout === 17
            && $prompt->attachments->count() === 2
            && $prompt->agent->providerOptions('openrouter') === ['route' => 'vision']
            && str_contains($prompt->prompt, '2, 4');
    });
    InlineSchemaAgent::assertPromptedTimes(2);
    Http::assertNothingSent();
});

it('retains successful siblings and explicit failed groups after operational extraction failure', function (): void {
    DocumentDetectionAgent::fake([['groups' => [
        ['pages' => [1], 'ambiguous' => false],
        ['pages' => [2], 'ambiguous' => false],
    ]]])->preventStrayPrompts();
    $attempt = 0;
    InlineSchemaAgent::fake(function () use (&$attempt): array {
        if ($attempt++ === 0) {
            throw new AiException('private provider body');
        }

        return ['value' => 'second survived'];
    })->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromPath(groupingPdf())
        ->pages([1, 2])
        ->detectDocuments()
        ->schema(groupingSchema(...))
        ->extract();

    expect($result->documents)->toHaveCount(2)
        ->and($result->documents->first()?->complete())->toBeFalse()
        ->and($result->documents->first()?->error?->code)->toBe('provider_failed')
        ->and($result->documents->last()?->data)->toBe(['value' => 'second survived'])
        ->and($result->errors->first()?->message)->not->toContain('private provider body')
        ->and($result->calls->pluck('outcome')->all())->toBe(['succeeded', 'failed', 'succeeded'])
        ->and($result->complete())->toBeFalse();
});

it('processes usable groups while exposing ambiguity and missing selected pages', function (): void {
    DocumentDetectionAgent::fake([['groups' => [
        ['pages' => [1], 'ambiguous' => false],
        ['pages' => [2], 'ambiguous' => true],
    ]]])->preventStrayPrompts();
    InlineSchemaAgent::fake([['value' => 'usable']])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromPath(groupingPdf())
        ->pages([1, 2, 3])
        ->detectDocuments()
        ->schema(groupingSchema(...))
        ->extract();

    expect($result->documents)->toHaveCount(2)
        ->and($result->documents->first()?->data)->toBe(['value' => 'usable'])
        ->and($result->documents->last()?->error?->code)->toBe('ambiguous_detection')
        ->and($result->errors->pluck('code')->all())->toBe(['unassigned_pages', 'ambiguous_detection'])
        ->and($result->complete())->toBeFalse();
});

it('does not invent a whole-source group for malformed empty or overlapping detection', function (mixed $response, string $code): void {
    DocumentDetectionAgent::fake([$response])->preventStrayPrompts();
    InlineSchemaAgent::fake([['value' => 'must not run']])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromPath(groupingPdf())
        ->pages([1, 2])
        ->detectDocuments()
        ->schema(groupingSchema(...))
        ->extract();

    expect($result->documents)->toBeEmpty()
        ->and($result->data)->toBeNull()
        ->and($result->errors->pluck('code')->all())->toContain($code)
        ->and($result->complete())->toBeFalse();
    InlineSchemaAgent::assertNeverPrompted();
})->with([
    'malformed JSON' => ['not json', 'detection_failed'],
    'missing group ambiguity' => [['groups' => [['pages' => [1, 2]]]], 'detection_failed'],
    'extra detector field' => [['groups' => [[
        'pages' => [1, 2],
        'ambiguous' => false,
        'invented' => true,
    ]]], 'detection_failed'],
    'empty groups' => [['groups' => []], 'detection_failed'],
    'overlap' => [['groups' => [
        ['pages' => [1, 2], 'ambiguous' => false],
        ['pages' => [2], 'ambiguous' => false],
    ]], 'invalid_detection_assignment'],
]);

it('groups direct page text and only OCRs pages that need it', function (): void {
    DocumentDetectionAgent::fake([['groups' => [
        ['pages' => [1, 2], 'ambiguous' => false],
        ['pages' => [3], 'ambiguous' => false],
    ]]])->preventStrayPrompts();
    OcrAgent::fake([])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromPath(groupingPdf())
        ->pages([1, 2, 3])
        ->detectDocuments()
        ->text();

    expect($result->text)->toBeNull()
        ->and($result->documents)->toHaveCount(2)
        ->and($result->documents->first()?->text)->toContain('PAGE 1 OF 5 MARKER BRAVO')
        ->and($result->documents->first()?->text)->toContain('PAGE 2 OF 5 MARKER BRAVO')
        ->and($result->documents->last()?->text)->toContain('PAGE 3 OF 5 MARKER BRAVO')
        ->and($result->pages->pluck('page')->all())->toBe([1, 2, 3])
        ->and($result->calls->pluck('stage')->all())->toBe(['detection'])
        ->and($result->complete())->toBeTrue();
    OcrAgent::assertNeverPrompted();
});

it('keeps partial text when OCR fails inside one detected group', function (): void {
    DocumentDetectionAgent::fake([['groups' => [
        ['pages' => [1, 2], 'ambiguous' => false],
    ]]])->preventStrayPrompts();
    OcrAgent::fake([
        'first visual page',
        fn (): never => throw new AiException('private OCR provider body'),
    ])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromPath(__DIR__.'/../Fixtures/Images/multipage.tiff')
        ->detectDocuments()
        ->text('openai', 'ocr-model');

    expect($result->documents)->toHaveCount(1)
        ->and($result->documents->first()?->text)->toBe("first visual page\f")
        ->and($result->documents->first()?->complete())->toBeFalse()
        ->and($result->pages->first()?->text)->toBe('first visual page')
        ->and($result->pages->last()?->error?->code)->toBe('provider_failed')
        ->and($result->errors->first()?->message)->not->toContain('private OCR provider body')
        ->and($result->calls->pluck('stage')->all())->toBe(['detection', 'ocr', 'ocr'])
        ->and($result->complete())->toBeFalse();
});

it('returns an explicit empty detection outcome when the detector provider fails', function (): void {
    DocumentDetectionAgent::fake([
        fn (): never => throw new AiException('private detector provider body'),
    ])->preventStrayPrompts();

    $result = app(DocumentExtraction::class)
        ->fromPath(groupingPdf())
        ->pages([1, 2])
        ->detectDocuments()
        ->text();

    expect($result->documents)->toBeEmpty()
        ->and($result->errors->first()?->code)->toBe('detection_failed')
        ->and($result->errors->first()?->message)->not->toContain('private detector provider body')
        ->and($result->calls->pluck('stage')->all())->toBe(['detection'])
        ->and($result->complete())->toBeFalse();
});

it('shares the global attempt limit across detection and every group', function (): void {
    config()->set('extraction.limits.ai_attempts', 2);
    DocumentDetectionAgent::fake([['groups' => [
        ['pages' => [1], 'ambiguous' => false],
        ['pages' => [2], 'ambiguous' => false],
    ]]])->preventStrayPrompts();
    InlineSchemaAgent::fake([
        ['value' => 'first'],
        ['value' => 'must not run'],
    ])->preventStrayPrompts();

    try {
        app(DocumentExtraction::class)
            ->fromPath(groupingPdf())
            ->pages([1, 2])
            ->detectDocuments()
            ->schema(groupingSchema(...))
            ->extract();

        throw new RuntimeException('The shared attempt limit should stop the second group.');
    } catch (AiExecutionException $exception) {
        expect($exception->errorCode)->toBe('ai_attempt_limit_exceeded')
            ->and($exception->partialResult?->detectionMode)->toBeTrue()
            ->and($exception->partialResult?->documents)->toHaveCount(1)
            ->and($exception->partialResult?->documents->first()?->data)->toBe(['value' => 'first'])
            ->and($exception->partialResult?->calls->pluck('stage')->all())->toBe(['detection', 'extraction'])
            ->and($exception->partialResult?->complete())->toBeFalse();
    }
});

it('fails oversized detector context before model egress', function (): void {
    config()->set('extraction.limits.inline_attachment_bytes', 1);
    DocumentDetectionAgent::fake([['groups' => []]])->preventStrayPrompts();

    expect(fn () => app(DocumentExtraction::class)
        ->fromPath(groupingPdf())
        ->pages([1])
        ->detectDocuments()
        ->text())
        ->toThrow(AiExecutionException::class, 'pre-base64');

    DocumentDetectionAgent::assertNeverPrompted();
});
