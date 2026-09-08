<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Jkudish\DocumentExtraction\AI\InlineSchemaAgent;
use Jkudish\DocumentExtraction\Facades\Extraction;
use Jkudish\DocumentExtraction\Tests\TestCase;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\LaravelAi\BenchmarkAgentMiddleware;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;

uses(TestCase::class);

final class RequiredExtractionFieldsScorer implements Scorer
{
    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        $actual = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $required = json_decode($expected ?? '{}', true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($actual) || ! is_array($required) || $required === []) {
            return new ScorerResult(0.0, 'Expected two JSON objects with required fields.', 'required-extraction-fields');
        }

        $matches = 0;

        foreach ($required as $key => $value) {
            if (is_string($key) && array_key_exists($key, $actual) && $actual[$key] === $value) {
                $matches++;
            }
        }

        $score = $matches / count($required);

        return new ScorerResult(
            score: $score,
            reasoning: sprintf('%d of %d required extraction fields matched.', $matches, count($required)),
            scorer: 'required-extraction-fields',
        );
    }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set([
        'ai.providers.openai.key' => 'offline-test-key',
        'ai-pricing.offline' => true,
        'ai-pricing.prices' => [],
        'extraction.provider' => 'openai',
        'extraction.model' => 'production-model',
        'extraction.timeout' => 33,
        'extraction.options' => ['openai' => ['temperature' => 0.1]],
        'extraction.middleware' => [new BenchmarkAgentMiddleware],
    ]);

    benchmarks()->configure(
        provider: 'extraction.provider',
        model: 'extraction.model',
        options: 'extraction.options',
        settings: ['extraction.timeout'],
    );

    InlineSchemaAgent::fake(function (
        string $prompt,
        Collection $attachments,
        TextProvider $provider,
        string $model,
    ): StructuredTextResponse {
        expect($prompt)->toContain('fixture source')
            ->and($attachments)->toBeEmpty();

        return new StructuredTextResponse(
            structured: ['document_type' => 'fixture', 'source_text' => 'fixture source'],
            text: '{"document_type":"fixture","source_text":"fixture source"}',
            usage: new Usage(promptTokens: 11, completionTokens: 7),
            meta: new Meta(provider: $provider->name(), model: $model),
        );
    })->preventStrayPrompts();
});

afterEach(function (): void {
    expect(config('extraction.provider'))->toBe('openai')
        ->and(config('extraction.model'))->toBe('production-model')
        ->and(config('extraction.timeout'))->toBe(33)
        ->and(config('extraction.options'))->toBe(['openai' => ['temperature' => 0.1]]);

    Http::assertNothingSent();
});

benchmark('extracts text through the production package path', function (): array {
    $marker = getenv('LDE_EVAL_EXECUTION_MARKER');

    if (is_string($marker) && $marker !== '') {
        file_put_contents($marker, "executed\n", FILE_APPEND | LOCK_EX);
    }

    $pending = Extraction::fromString("fixture source\n", 'text/plain')
        ->schema(fn (JsonSchema $schema): array => [
            'document_type' => $schema->string()->required(),
            'source_text' => $schema->string()->required(),
        ]);
    $configuration = $pending->configuration();

    expect($configuration['provider'])->toBe(config('extraction.provider'))
        ->and($configuration['model'])->toBe(config('extraction.model'))
        ->and($configuration['timeout'])->toBe(config('extraction.timeout'))
        ->and($configuration['options'])->toBe(config('extraction.options'));

    $result = $pending->extract();

    expect($result->calls)->toHaveCount(1)
        ->and($result->calls->sole()->usage?->toArray())->toBe((new Usage(11, 7))->toArray())
        ->and($result->calls->sole()->cost)->toBeNull()
        ->and($result->cost->knownByCurrency)->toBeEmpty();

    return $result->data ?? [];
})->configurations([
    'production' => Configuration::production(),
    'candidate' => Configuration::model(
        provider: 'openai',
        model: 'candidate-model',
        options: ['openai' => ['temperature' => 0.2]],
    )->withSettings(['extraction.timeout' => 44]),
])->evaluate(function (mixed $output, mixed ...$arguments): void {
    expect($arguments)->toBeEmpty()
        ->and($output)->toBeArray();

    /** @phpstan-ignore-next-line Pest AI Benchmarks registers this expectation extension at plugin boot. */
    expect(json_encode($output, JSON_THROW_ON_ERROR))->toPassBenchmarkScorer(
        scorer: new RequiredExtractionFieldsScorer,
        threshold: 1.0,
        expected: '{"document_type":"fixture","source_text":"fixture source"}',
    );
})->dependsOn([
    InlineSchemaAgent::class,
    'config/extraction.php',
]);
