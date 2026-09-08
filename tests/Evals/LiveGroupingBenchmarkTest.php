<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Jkudish\DocumentExtraction\AI\InlineSchemaAgent;
use Jkudish\DocumentExtraction\Facades\Extraction;
use Jkudish\DocumentExtraction\Results\CallRecord;
use Jkudish\DocumentExtraction\Results\EvidenceOrigin;
use Jkudish\DocumentExtraction\Tests\Support\GroupingBenchmarkCorpus;
use Jkudish\DocumentExtraction\Tests\Support\GroupingMetric;
use Jkudish\DocumentExtraction\Tests\Support\GroupingMetricScorer;
use Jkudish\DocumentExtraction\Tests\Support\GroupingScorerRecorder;
use Jkudish\DocumentExtraction\Tests\Support\LiveGroupingAttemptScorer;
use Jkudish\DocumentExtraction\Tests\Support\LiveGroupingModels;
use Jkudish\DocumentExtraction\Tests\TestCase;
use Jkudish\PestAiBenchmarks\LaravelAi\BenchmarkAgentMiddleware;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set([
        'ai.providers.openrouter.key' => getenv('OPENROUTER_API_KEY') ?: null,
        'ai-pricing.offline' => true,
        'ai-pricing.prices' => [],
        'extraction.provider' => 'openai',
        'extraction.model' => 'live-screen-restoration-sentinel',
        'extraction.timeout' => 33,
        'extraction.options' => ['openai' => ['temperature' => 0.1]],
        'extraction.middleware' => [new BenchmarkAgentMiddleware],
        'extraction.detection' => [
            'provider' => 'openai',
            'model' => 'live-screen-restoration-detector',
            'timeout' => 32,
            'options' => ['openai' => ['temperature' => 0.1]],
        ],
    ]);

    benchmarks()->configure(
        provider: 'extraction.provider',
        model: 'extraction.model',
        options: 'extraction.options',
        settings: [
            'extraction.timeout',
            'extraction.detection',
            'extraction.preparation',
            'extraction.limits',
        ],
    );

    InlineSchemaAgent::fake(function (
        string $prompt,
        Collection $attachments,
        TextProvider $provider,
        string $model,
    ): StructuredTextResponse {
        expect($prompt)->not->toContain(LiveGroupingModels::FIXTURE_ID)
            ->not->toContain('expected')
            ->and($attachments)->not->toBeEmpty();

        $structured = ['value' => 'simulated grouped extraction'];

        return new StructuredTextResponse(
            structured: $structured,
            text: json_encode($structured, JSON_THROW_ON_ERROR),
            usage: new Usage(promptTokens: 17, completionTokens: 9),
            meta: new Meta(provider: $provider->name(), model: $model),
        );
    })->preventStrayPrompts();

    if (getenv('LDE_LIVE_GROUPING_OFFLINE') === '1') {
        Http::fake(function (Request $request) {
            $body = $request->data();
            $fixture = liveGroupingFixture();
            $model = $body['model'] ?? null;

            if (! is_string($model)) {
                throw new RuntimeException('The live grouping request did not select an approved model.');
            }

            $configured = LiveGroupingModels::find($model);
            $openRouterOptions = LiveGroupingModels::options($configured)['openrouter'];
            $responseFormat = $body['response_format'] ?? null;
            $jsonSchema = is_array($responseFormat) ? ($responseFormat['json_schema'] ?? null) : null;
            $messages = json_encode($body['messages'] ?? [], JSON_THROW_ON_ERROR);

            expect($request->url())->toBe('https://openrouter.ai/api/v1/chat/completions')
                ->and($body[$configured['output_parameter']] ?? null)->toBe(LiveGroupingModels::MAX_OUTPUT_TOKENS)
                ->and($body['provider'] ?? null)->toBe($openRouterOptions['provider'])
                ->and($body['reasoning'] ?? null)->toBe($openRouterOptions['reasoning'] ?? null)
                ->and(is_array($responseFormat) ? ($responseFormat['type'] ?? null) : null)->toBe('json_schema')
                ->and(is_array($jsonSchema) ? ($jsonSchema['strict'] ?? null) : null)->toBeFalse()
                ->and(substr_count($messages, '"type":"image_url"'))->toBe($fixture['page_count'])
                ->and($messages)
                ->not->toContain(LiveGroupingModels::FIXTURE_ID)
                ->not->toContain('expected');

            $groups = array_map(
                static fn (array $pages): array => ['pages' => $pages, 'ambiguous' => false],
                $fixture['expected']['groups'],
            );
            $content = json_encode(['groups' => $groups], JSON_THROW_ON_ERROR);

            return Http::response([
                'id' => 'offline-live-screen',
                'model' => $model,
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => $content],
                ]],
                'usage' => [
                    'prompt_tokens' => 101,
                    'completion_tokens' => 19,
                    'cost' => '0.012345',
                ],
            ]);
        });
    } else {
        Http::record();
        Http::allowStrayRequests(['https://openrouter.ai/api/v1/chat/completions']);
    }
});

afterEach(function (): void {
    expect(config('extraction.provider'))->toBe('openai')
        ->and(config('extraction.model'))->toBe('live-screen-restoration-sentinel')
        ->and(config('extraction.timeout'))->toBe(33)
        ->and(config('extraction.options'))->toBe(['openai' => ['temperature' => 0.1]])
        ->and(config('extraction.detection.model'))->toBe('live-screen-restoration-detector')
        ->and(config('extraction.detection.timeout'))->toBe(32)
        ->and(config('extraction.detection.options'))->toBe(['openai' => ['temperature' => 0.1]]);

    if (getenv('LDE_LIVE_GROUPING_CONFIRM') === LiveGroupingModels::CONFIRMATION) {
        Http::assertSentCount(1);
    } else {
        Http::assertNothingSent();
    }
});

benchmark(LiveGroupingModels::BENCHMARK, function (): array {
    expect(getenv('LDE_LIVE_GROUPING_CONFIRM'))->toBe(LiveGroupingModels::CONFIRMATION);

    $fixture = liveGroupingFixture();
    $path = GroupingBenchmarkCorpus::fixturePath($fixture['file']);
    $pending = Extraction::fromPath($path)
        ->detectDocuments()
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()]);
    $configuration = $pending->configuration();

    expect($fixture['split'])->toBe('prompt-example')
        ->and($fixture['id'])->toBe(LiveGroupingModels::FIXTURE_ID)
        ->and($fixture['file'])->toBe(LiveGroupingModels::FIXTURE_FILE)
        ->and($configuration['provider'])->toBe('openrouter')
        ->and($configuration['detection']['provider'])->toBe('openrouter');

    $result = $pending->extract();

    expect($result->sourceSha256)->toBe($fixture['sha256'])
        ->and($result->pageCount)->toBe($fixture['page_count'])
        ->and(hash_file('sha256', $path))->toBe($fixture['sha256'])
        ->and($result->calls->filter(
            static fn (CallRecord $call): bool => $call->evidenceOrigin === EvidenceOrigin::Live,
        ))->toHaveCount(1);

    return GroupingBenchmarkCorpus::output($result, $fixture);
})->configurations(LiveGroupingModels::configurations(
    (($onlyModel = getenv('LDE_LIVE_GROUPING_MODEL')) !== false && $onlyModel !== '') ? $onlyModel : null,
))
    ->context([
        ...GroupingBenchmarkCorpus::scorecardContext(),
        'screen' => 'openrouter-live-grouping-canary-v1',
        'fixture' => LiveGroupingModels::FIXTURE_ID,
        'paid_detector_calls' => count(LiveGroupingModels::all()),
        'max_spend_usd' => LiveGroupingModels::MAX_SPEND_USD,
    ])
    ->evaluate(function (mixed $output): void {
        $fixture = liveGroupingFixture();
        $output = GroupingBenchmarkCorpus::outputObject($output);
        $expected = json_encode($fixture['expected'], JSON_THROW_ON_ERROR);
        $json = json_encode($output, JSON_THROW_ON_ERROR);

        foreach (GroupingMetric::cases() as $metric) {
            GroupingScorerRecorder::record(
                output: $json,
                scorer: new GroupingMetricScorer($metric),
                expected: $expected,
                threshold: 0.0,
            );
        }

        GroupingScorerRecorder::record(
            output: $json,
            scorer: new LiveGroupingAttemptScorer,
        );
    })->dependsOn([
        ...GroupingBenchmarkCorpus::dependencies(),
        'tests/Evals/LiveGroupingBenchmarkTest.php',
        'tests/Support/LiveGroupingAttemptScorer.php',
        'tests/Support/LiveGroupingModels.php',
    ]);

/** @return array{id: string, file: string, split: string, description: string, page_count: int, sha256: string, size: int, content: string, expected: array{groups: list<list<int>>, unassigned_pages: list<int>, ambiguous_pages: list<int>, unassigned_reasons: array<int, string>}, runtime: array<string, string>} */
function liveGroupingFixture(): array
{
    foreach (GroupingBenchmarkCorpus::fixtures() as [$fixture]) {
        if ($fixture['id'] === LiveGroupingModels::FIXTURE_ID) {
            return $fixture;
        }
    }

    throw new RuntimeException('The approved live grouping fixture was not found.');
}
