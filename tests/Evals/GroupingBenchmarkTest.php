<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Jkudish\DocumentExtraction\Facades\Extraction;
use Jkudish\DocumentExtraction\Tests\Support\GroupingAttemptScorer;
use Jkudish\DocumentExtraction\Tests\Support\GroupingBenchmarkCorpus;
use Jkudish\DocumentExtraction\Tests\Support\GroupingMetric;
use Jkudish\DocumentExtraction\Tests\Support\GroupingMetricScorer;
use Jkudish\DocumentExtraction\Tests\Support\GroupingScorerRecorder;
use Jkudish\DocumentExtraction\Tests\TestCase;
use Jkudish\PestAiBenchmarks\BenchmarkExecutor;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\LaravelAi\BenchmarkAgentMiddleware;
use Jkudish\PestAiBenchmarks\Reporters\ExecutionRecorder;

uses(TestCase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set([
        'ai.providers.openai.key' => null,
        'ai.providers.openrouter.key' => null,
        'ai-pricing.offline' => true,
        'ai-pricing.prices' => [],
        'extraction.provider' => 'openai',
        'extraction.model' => 'offline-production',
        'extraction.timeout' => 33,
        'extraction.options' => [
            'openai' => [
                'endpoint' => 'offline://production',
                'fallback' => ['enabled' => false],
                'reasoning' => ['effort' => 'minimal'],
                'route' => ['primary' => 'offline-production'],
            ],
        ],
        'extraction.middleware' => [new BenchmarkAgentMiddleware],
        'extraction.ocr' => [
            'provider' => 'openai',
            'model' => 'offline-ocr',
            'timeout' => 31,
            'options' => ['openai' => ['endpoint' => 'offline://ocr']],
        ],
        'extraction.detection' => [
            'provider' => 'openai',
            'model' => 'offline-detector',
            'timeout' => 32,
            'options' => [
                'openai' => [
                    'endpoint' => 'offline://detector',
                    'fallback' => ['enabled' => false],
                    'reasoning' => ['effort' => 'minimal'],
                    'route' => ['primary' => 'offline-detector'],
                ],
            ],
        ],
    ]);

    benchmarks()->configure(
        provider: 'extraction.provider',
        model: 'extraction.model',
        options: 'extraction.options',
        settings: [
            'extraction.timeout',
            'extraction.ocr',
            'extraction.detection',
            'extraction.preparation',
            'extraction.limits',
        ],
    );
});

afterEach(function (): void {
    expect(config('extraction.provider'))->toBe('openai')
        ->and(config('extraction.model'))->toBe('offline-production')
        ->and(config('extraction.timeout'))->toBe(33)
        ->and(config('extraction.options.openai.endpoint'))->toBe('offline://production')
        ->and(config('extraction.detection.model'))->toBe('offline-detector')
        ->and(config('extraction.detection.options.openai.endpoint'))->toBe('offline://detector')
        ->and(config('extraction.ocr.model'))->toBe('offline-ocr');

    Http::assertNothingSent();
});

it('loads the eight-fixture thirty-three-page synthetic corpus', function (): void {
    $manifest = GroupingBenchmarkCorpus::manifest();

    expect($manifest['fixtures'])->toHaveCount(8)
        ->and(array_sum(array_column($manifest['fixtures'], 'page_count')))->toBe(33)
        ->and(array_column($manifest['fixtures'], 'id'))->toHaveCount(8);
});

it('reports each deterministic grouping failure independently', function (GroupingMetric $metric, array $mutation): void {
    $truth = [
        'groups' => [[1, 2], [3]],
        'unassigned_pages' => [4],
        'ambiguous_pages' => [4],
    ];
    $actual = [
        'selected_pages' => [1, 2, 3, 4],
        'groups' => [[1, 2], [3]],
        'unassigned_pages' => [4],
        'ambiguous_pages' => [4],
        'errors' => [],
        ...$mutation,
    ];
    $result = (new GroupingMetricScorer($metric))->score(
        '',
        json_encode($actual, JSON_THROW_ON_ERROR),
        json_encode($truth, JSON_THROW_ON_ERROR),
    );

    expect($result->score)->toBe(0.0)
        ->and($result->scorer)->toBe($metric->value)
        ->and($result->reasoning)->not->toBeEmpty();
})->with([
    'exact groups' => [GroupingMetric::ExactGroups, ['groups' => [[1, 2, 3]]]],
    'merge pairs' => [GroupingMetric::MergeErrors, ['groups' => [[1, 2, 3]]]],
    'split pairs' => [GroupingMetric::SplitErrors, ['groups' => [[1], [2], [3]]]],
    'coverage' => [GroupingMetric::SelectedPageCoverage, ['unassigned_pages' => [3, 4]]],
    'ambiguity' => [GroupingMetric::Ambiguity, ['ambiguous_pages' => []]],
    'unassigned pages' => [GroupingMetric::UnassignedPages, ['unassigned_pages' => [3, 4]]],
    'schema failures' => [GroupingMetric::SchemaFailures, ['errors' => [[
        'code' => 'invalid_output',
        'retryable' => false,
    ]]]],
    'membership failures' => [GroupingMetric::MembershipFailures, ['errors' => [[
        'code' => 'invalid_detection_assignment',
        'retryable' => false,
    ]]]],
    'provider failures' => [GroupingMetric::ProviderFailures, ['errors' => [[
        'code' => 'provider_failed',
        'retryable' => true,
    ]]]],
]);

it('keeps merge split and membership failure metrics independent', function (array $mutation, GroupingMetric $failed): void {
    $truth = [
        'groups' => [[1, 2], [3]],
        'unassigned_pages' => [],
        'ambiguous_pages' => [],
    ];
    $actual = [
        'selected_pages' => [1, 2, 3],
        'groups' => [[1, 2], [3]],
        'unassigned_pages' => [],
        'ambiguous_pages' => [],
        'errors' => [],
        ...$mutation,
    ];
    $output = json_encode($actual, JSON_THROW_ON_ERROR);
    $expected = json_encode($truth, JSON_THROW_ON_ERROR);
    $failedScore = (new GroupingMetricScorer($failed))->score('', $output, $expected)->score;
    $otherScores = [];

    foreach ([GroupingMetric::MergeErrors, GroupingMetric::SplitErrors, GroupingMetric::MembershipFailures] as $metric) {
        if ($metric !== $failed) {
            $otherScores[] = (new GroupingMetricScorer($metric))->score('', $output, $expected)->score;
        }
    }

    expect($failedScore)->toBe(0.0)
        ->and($otherScores)->each->toBe(1.0);
})->with([
    'merge only' => [['groups' => [[1, 2, 3]]], GroupingMetric::MergeErrors],
    'split only' => [['groups' => [[1], [2], [3]]], GroupingMetric::SplitErrors],
    'membership failure only' => [['errors' => [[
        'code' => 'invalid_detection_assignment',
        'retryable' => false,
    ]]], GroupingMetric::MembershipFailures],
]);

it('rejects malformed grouping evidence instead of normalizing it', function (array $mutation): void {
    $truth = [
        'groups' => [[1, 2]],
        'unassigned_pages' => [],
        'ambiguous_pages' => [],
    ];
    $actual = [
        'selected_pages' => [1, 2],
        'groups' => [[1, 2]],
        'unassigned_pages' => [],
        'ambiguous_pages' => [],
        'errors' => [],
        ...$mutation,
    ];
    $result = (new GroupingMetricScorer(GroupingMetric::ExactGroups))->score(
        '',
        json_encode($actual, JSON_THROW_ON_ERROR),
        json_encode($truth, JSON_THROW_ON_ERROR),
    );

    expect($result->score)->toBe(0.0)
        ->and($result->reasoning)->toBe('The grouping result or expected truth was malformed.');
})->with([
    'string page' => [['groups' => [[1, '2']]]],
    'duplicate page' => [['groups' => [[1, 1]]]],
    'empty group' => [['groups' => [[]]]],
    'malformed error' => [['errors' => [['code' => 'provider_failed']]]],
]);

it('invalidates replay identity when runtime evidence changes', function (): void {
    $fixture = GroupingBenchmarkCorpus::manifest()['fixtures'][0];
    $first = [...$fixture, 'runtime' => GroupingBenchmarkCorpus::runtimeEvidence('first')];
    $second = [...$fixture, 'runtime' => GroupingBenchmarkCorpus::runtimeEvidence('second')];

    expect(ExecutionRecorder::caseId([$first]))
        ->not->toBe(ExecutionRecorder::caseId([$second]));
});

it('freezes every prompt schema corpus runtime and scorer dependency', function (): void {
    $dependencies = GroupingBenchmarkCorpus::dependencies();
    $evidence = ExecutionRecorder::dependencyIdentity($dependencies);
    $configurationEvidence = (new BenchmarkExecutor)->fingerprint(Configuration::production());
    $changedEvidence = $evidence;
    $changedEvidence[0] = [
        'source' => $evidence[0]['source'],
        'sha256' => hash('sha256', 'changed source'),
    ];
    $targetIdentity = ExecutionRecorder::targetIdentity(static fn (): null => null);
    $fingerprint = ExecutionRecorder::trialFingerprint(
        benchmark: 'fingerprint proof',
        caseId: 'case_fixture',
        configurationName: 'production',
        configuration: Configuration::production(),
        targetIdentity: $targetIdentity,
        evaluationIdentity: null,
        dependencyEvidence: $configurationEvidence,
        sourceDependencies: $evidence,
    );
    $changedFingerprint = ExecutionRecorder::trialFingerprint(
        benchmark: 'fingerprint proof',
        caseId: 'case_fixture',
        configurationName: 'production',
        configuration: Configuration::production(),
        targetIdentity: $targetIdentity,
        evaluationIdentity: null,
        dependencyEvidence: $configurationEvidence,
        sourceDependencies: $changedEvidence,
    );

    expect($dependencies)
        ->toContain(
            'tests/Evals/GroupingBenchmarkTest.php',
            'tests/Support/GroupingMetricScorer.php',
            'tests/Fixtures/Grouping/manifest.json',
            'tests/Fixtures/Grouping/bundle-08.pdf',
            'config/extraction.php',
            'composer.lock',
            'src/AI/DocumentDetectionAgent.php',
            'src/AI/NativeAiProcessor.php',
            'src/AI/PageGroupValidator.php',
            'src/DocumentExtraction.php',
            'src/PendingExtraction.php',
            'src/Preparation/DocumentPreparer.php',
            'src/Results/CallRecord.php',
            'src/Results/CostSummary.php',
        )
        ->and($evidence)->toHaveCount(count($dependencies))
        ->and(array_column($evidence, 'sha256'))->each->toMatch('/^[a-f0-9]{64}$/')
        ->and($configurationEvidence)->toHaveKeys([
            'extraction.provider',
            'extraction.model',
            'extraction.options',
            'extraction.timeout',
            'extraction.ocr',
            'extraction.detection',
            'extraction.preparation',
            'extraction.limits',
        ])
        ->and($fingerprint)->not->toBe($changedFingerprint);
});

it('rejects duplicate native calls or aggregate pricing ingestion', function (array $mutation): void {
    $output = [
        'calls' => [
            ['reference' => 'invocation:1', 'ordinal' => 1, 'mode' => 'simulated', 'cost_quote' => null],
            ['reference' => 'invocation:2', 'ordinal' => 2, 'mode' => 'simulated', 'cost_quote' => null],
        ],
        'cost' => [
            'known_by_currency' => [],
            'unpriced_calls' => ['invocation:1', 'invocation:2'],
            'complete' => false,
            'mode' => 'simulated',
        ],
        ...$mutation,
    ];
    $result = (new GroupingAttemptScorer)->score('', json_encode($output, JSON_THROW_ON_ERROR));

    expect($result->score)->toBe(0.0);
})->with([
    'duplicate native reference' => [[
        'calls' => [
            ['reference' => 'invocation:1', 'ordinal' => 1, 'mode' => 'simulated', 'cost_quote' => null],
            ['reference' => 'invocation:1', 'ordinal' => 2, 'mode' => 'simulated', 'cost_quote' => null],
        ],
    ]],
    'known aggregate cost' => [[
        'cost' => [
            'known_by_currency' => ['USD' => ['currency' => 'USD', 'amount' => '1.00']],
            'unpriced_calls' => ['invocation:1', 'invocation:2'],
            'complete' => true,
            'mode' => 'simulated',
        ],
    ]],
]);

benchmark('offline grouping corpus scores the public detectDocuments path', function (mixed $case = null): array {
    $fixture = GroupingBenchmarkCorpus::fixture($case);
    GroupingBenchmarkCorpus::fakeAgents($fixture);
    $path = GroupingBenchmarkCorpus::fixturePath($fixture['file']);
    $pending = Extraction::fromPath($path)
        ->detectDocuments()
        ->schema(fn (JsonSchema $schema): array => ['value' => $schema->string()->required()]);
    $configuration = $pending->configuration();

    expect($configuration['middleware'])->toHaveCount(1)
        ->and(config('extraction.provider'))->toBeString()
        ->and(config('extraction.model'))->toBeString();

    $result = $pending->extract();

    expect($result->sourceSha256)->toBe($fixture['sha256'])
        ->and($result->pageCount)->toBe($fixture['page_count'])
        ->and(hash_file('sha256', $path))->toBe($fixture['sha256']);

    return GroupingBenchmarkCorpus::output($result, $fixture);
})->with(GroupingBenchmarkCorpus::fixtures())->configurations([
    'production' => Configuration::production(),
    'offline-candidate' => Configuration::model(
        provider: 'openai',
        model: 'offline-candidate',
        options: [
            'openai' => [
                'endpoint' => 'offline://candidate',
                'fallback' => ['enabled' => false],
                'reasoning' => ['effort' => 'minimal'],
                'route' => ['primary' => 'offline-candidate'],
            ],
        ],
    )->withSettings([
        'extraction.timeout' => 44,
        'extraction.detection' => [
            'provider' => 'openrouter',
            'model' => 'offline-detector-candidate',
            'timeout' => 43,
            'options' => [
                'openrouter' => [
                    'endpoint' => 'offline://detector-candidate',
                    'fallback' => ['enabled' => false],
                    'reasoning' => ['effort' => 'minimal'],
                    'route' => ['primary' => 'offline-detector-candidate'],
                ],
            ],
        ],
    ]),
])->context(GroupingBenchmarkCorpus::scorecardContext())
    ->evaluate(function (mixed $output, mixed $case = null): void {
        $fixture = GroupingBenchmarkCorpus::fixture($case);
        $output = GroupingBenchmarkCorpus::outputObject($output);
        expect($output['fixture_id'] ?? null)->toBe($fixture['id'])
            ->and($output['source_sha256'] ?? null)->toBe($fixture['sha256']);

        $expected = json_encode($fixture['expected'], JSON_THROW_ON_ERROR);
        $json = json_encode($output, JSON_THROW_ON_ERROR);

        foreach (GroupingMetric::cases() as $metric) {
            GroupingScorerRecorder::record(
                output: $json,
                scorer: new GroupingMetricScorer($metric),
                expected: $expected,
            );
        }

        GroupingScorerRecorder::record(
            output: $json,
            scorer: new GroupingAttemptScorer,
        );
    })->dependsOn(GroupingBenchmarkCorpus::dependencies());
