<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev;

use Jkudish\DocumentExtraction\Tests\Support\GroupingAttemptScorer;
use Jkudish\DocumentExtraction\Tests\Support\GroupingBenchmarkCorpus;
use Jkudish\DocumentExtraction\Tests\Support\GroupingMetric;
use Jkudish\DocumentExtraction\Tests\Support\GroupingMetricScorer;
use Jkudish\PestAiBenchmarks\Runs\StableScorecardValidator;
use RuntimeException;

final class GroupingScorecardValidator
{
    /** @return array{trials: int, fixtures: int, pages: int, attempts: int, scorer_results: int} */
    public function validate(string $runDirectory): array
    {
        $directory = realpath($runDirectory);

        if ($directory === false || ! is_dir($directory)) {
            throw new RuntimeException('The grouping benchmark run directory does not exist.');
        }

        $scorecard = $this->jsonObject($directory.'/scorecard.json');
        $replay = $this->jsonObject($directory.'/replay.private.json');
        StableScorecardValidator::assert($scorecard);

        $expectedFixtures = [];

        foreach (GroupingBenchmarkCorpus::manifest()['fixtures'] as $fixture) {
            $expectedFixtures[$fixture['id']] = $fixture;
        }

        $trials = $this->list($scorecard['trials'] ?? null, 'scorecard trials');
        $replayTrials = $this->list($replay['trials'] ?? null, 'replay trials');

        if (($scorecard['benchmark'] ?? null) !== 'offline grouping corpus scores the public detectDocuments path'
            || count($trials) !== 16
            || ($replay['schema_version'] ?? null) !== '0.1.0'
            || count($replayTrials) !== count($trials)) {
            throw new RuntimeException('The grouping benchmark run has an unexpected identity or trial count.');
        }

        $context = $this->object($scorecard['context'] ?? null, 'scorecard context');
        $expectedContext = GroupingBenchmarkCorpus::scorecardContext();

        if ($this->canonical($context) !== $this->canonical($expectedContext)) {
            throw new RuntimeException('The grouping benchmark context does not match the current corpus and runtime.');
        }

        $replayByTrial = [];

        foreach ($replayTrials as $replayTrial) {
            $entry = $this->object($replayTrial, 'replay trial');
            $trialId = $this->string($entry['trial_id'] ?? null, 'replay trial ID');

            if (isset($replayByTrial[$trialId])) {
                throw new RuntimeException('The grouping benchmark replay contains a duplicate trial.');
            }

            $replayByTrial[$trialId] = $entry;
        }

        $fixturePages = [];
        $fixtureConfigurations = [];
        $configurations = [];
        $attempts = 0;
        $scorerResults = 0;
        $expectedScorers = [
            'pest:test',
            ...array_map(static fn (GroupingMetric $metric): string => $metric->value, GroupingMetric::cases()),
            'native-attempt-integrity',
        ];

        foreach ($trials as $rawTrial) {
            $trial = $this->object($rawTrial, 'scorecard trial');
            $trialId = $this->string($trial['trial_id'] ?? null, 'scorecard trial ID');
            $replayTrial = $replayByTrial[$trialId] ?? throw new RuntimeException('A scorecard trial has no replay output.');

            if (($trial['fingerprint'] ?? null) !== ($replayTrial['fingerprint'] ?? null)) {
                throw new RuntimeException('Scorecard and replay trial fingerprints differ.');
            }

            $output = $this->object($replayTrial['output'] ?? null, 'replay output');
            $fixtureId = $this->string($output['fixture_id'] ?? null, 'fixture ID');
            $fixture = $expectedFixtures[$fixtureId] ?? throw new RuntimeException('A replay output names an unknown fixture.');
            $pageCount = $output['page_count'] ?? null;
            $selectedPages = $this->list($output['selected_pages'] ?? null, 'selected pages');

            if ($pageCount !== $fixture['page_count']
                || ($output['source_sha256'] ?? null) !== $fixture['sha256']
                || $selectedPages !== range(1, $fixture['page_count'])) {
                throw new RuntimeException('A replay output does not match its synthetic fixture identity.');
            }

            if (isset($fixturePages[$fixtureId]) && $fixturePages[$fixtureId] !== $pageCount) {
                throw new RuntimeException('A fixture has inconsistent page-count evidence.');
            }

            $fixturePages[$fixtureId] = $pageCount;
            $configuration = $this->string($trial['configuration'] ?? null, 'trial configuration');

            if (isset($fixtureConfigurations[$fixtureId][$configuration])) {
                throw new RuntimeException('A fixture and configuration pair appears more than once.');
            }

            $fixtureConfigurations[$fixtureId][$configuration] = true;
            $configurations[$configuration] = ($configurations[$configuration] ?? 0) + 1;
            $results = $this->list($trial['results'] ?? null, 'trial results');
            $scorers = [];

            foreach ($results as $rawResult) {
                $result = $this->object($rawResult, 'trial result');
                $scorers[] = $this->string($result['scorer'] ?? null, 'result scorer');

                if ((($result['score'] ?? null) !== 1.0 && ($result['score'] ?? null) !== 1)
                    || ($result['passed'] ?? null) !== true) {
                    throw new RuntimeException('The offline grouping scorecard contains a failed result.');
                }
            }

            if ($scorers !== $expectedScorers) {
                throw new RuntimeException('The offline grouping scorecard has an unexpected scorer set.');
            }

            $primary = $this->object($results[0] ?? null, 'primary result');
            $measurements = $this->list($primary['measurements'] ?? null, 'target measurements');
            $calls = $this->list($output['calls'] ?? null, 'native calls');
            $outputJson = json_encode($output, JSON_THROW_ON_ERROR);
            $expectedJson = json_encode($fixture['expected'], JSON_THROW_ON_ERROR);

            foreach (GroupingMetric::cases() as $metric) {
                if ((new GroupingMetricScorer($metric))->score('', $outputJson, $expectedJson)->score !== 1.0) {
                    throw new RuntimeException("Replay output fails the deterministic [{$metric->value}] scorer.");
                }
            }

            if ((new GroupingAttemptScorer)->score('', $outputJson)->score !== 1.0) {
                throw new RuntimeException('Replay output fails native attempt integrity validation.');
            }

            if (count($measurements) !== count($calls)) {
                throw new RuntimeException('Native attempts and target measurements are not one-to-one.');
            }

            foreach ($results as $rawResult) {
                $result = $this->object($rawResult, 'trial result');

                if ($this->list($result['measurements'] ?? null, 'result measurements') !== $measurements) {
                    throw new RuntimeException('Scorer results do not share the same native measurements.');
                }
            }

            foreach ($measurements as $index => $rawMeasurement) {
                $measurement = $this->object($rawMeasurement, 'target measurement');
                $pricing = $this->object($measurement['pricing'] ?? null, 'measurement pricing');
                $call = $this->object($calls[$index] ?? null, 'native call');

                if (($measurement['component'] ?? null) !== 'target'
                    || ($measurement['mode'] ?? null) !== 'simulated'
                    || ($pricing['completeness'] ?? null) !== 'unavailable') {
                    throw new RuntimeException('The dry-run measurement was not simulated and unpriced.');
                }

                $requestedModel = $this->object($measurement['requested_model'] ?? null, 'measurement requested model');
                $effectiveModel = $this->object($measurement['effective_model'] ?? null, 'measurement effective model');
                $requestedIdentity = $this->object($call['requested_identity'] ?? null, 'call requested identity');
                $effectiveIdentity = $this->object($call['effective_identity'] ?? null, 'call effective identity');
                $measurementUsage = $this->object($measurement['usage'] ?? null, 'measurement usage');
                $callUsage = $this->object($call['usage'] ?? null, 'call usage');
                $expectedUsage = [
                    'cached_input_tokens' => $callUsage['cache_read_input_tokens'] ?? null,
                    'input_tokens' => $callUsage['prompt_tokens'] ?? null,
                    'output_tokens' => $callUsage['completion_tokens'] ?? null,
                    'reasoning_tokens' => $callUsage['reasoning_tokens'] ?? null,
                ];

                if ($requestedModel !== $requestedIdentity
                    || $effectiveModel !== $effectiveIdentity
                    || $measurementUsage !== $expectedUsage) {
                    throw new RuntimeException('A target measurement identity or usage does not match its native call.');
                }
            }

            $references = [];
            $ordinals = [];
            $extractionInvocationId = null;

            foreach ($calls as $rawCall) {
                $call = $this->object($rawCall, 'native call');
                $reference = $this->string($call['reference'] ?? null, 'native call reference');
                $invocationId = $this->string($call['extraction_invocation_id'] ?? null, 'extraction invocation ID');
                $ordinal = $call['ordinal'] ?? null;

                if (! is_int($ordinal) || $ordinal < 1) {
                    throw new RuntimeException('A native call has an invalid ordinal.');
                }

                $references[] = $reference;
                $ordinals[] = $ordinal;
                $extractionInvocationId ??= $invocationId;
                $stage = $this->string($call['stage'] ?? null, 'native call stage');
                $requestedIdentity = $this->object($call['requested_identity'] ?? null, 'call requested identity');
                $expectedIdentity = match ([$configuration, $stage]) {
                    ['production', 'detection'] => ['provider' => 'openai', 'model' => 'offline-detector'],
                    ['production', 'extraction'] => ['provider' => 'openai', 'model' => 'offline-production'],
                    ['offline-candidate', 'detection'] => ['provider' => 'openrouter', 'model' => 'offline-detector-candidate'],
                    ['offline-candidate', 'extraction'] => ['provider' => 'openai', 'model' => 'offline-candidate'],
                    default => throw new RuntimeException('A native call has an unexpected configuration or stage.'),
                };

                if ($invocationId !== $extractionInvocationId
                    || $reference !== $invocationId.':'.$ordinal
                    || $requestedIdentity !== $expectedIdentity
                    || ($call['mode'] ?? null) !== 'simulated'
                    || ($call['outcome'] ?? null) !== 'succeeded'
                    || ($call['cost_quote'] ?? null) !== null) {
                    throw new RuntimeException('The dry-run call was not simulated and unpriced.');
                }
            }

            if (count(array_unique($references)) !== count($references)
                || $ordinals !== range(1, count($calls))) {
                throw new RuntimeException('Native call references or ordinals are duplicated or discontinuous.');
            }

            $cost = $this->object($output['cost'] ?? null, 'extraction cost summary');

            if (($cost['known_by_currency'] ?? null) !== []
                || ($cost['unpriced_calls'] ?? null) !== $references
                || ($cost['complete'] ?? null) !== false
                || ($cost['mode'] ?? null) !== 'simulated') {
                throw new RuntimeException('Extraction totals do not preserve simulated call evidence.');
            }

            $attempts += count($calls);
            $scorerResults += count($results);
        }

        ksort($configurations);

        foreach ($expectedFixtures as $fixtureId => $_fixture) {
            $fixtureConfigurationNames = array_keys($fixtureConfigurations[$fixtureId] ?? []);
            sort($fixtureConfigurationNames);

            if ($fixtureConfigurationNames !== ['offline-candidate', 'production']) {
                throw new RuntimeException('Each fixture must run exactly once in both offline configurations.');
            }
        }

        if (count($fixturePages) !== count($expectedFixtures)
            || array_sum($fixturePages) !== 33
            || $configurations !== ['offline-candidate' => 8, 'production' => 8]
            || $attempts !== 50
            || $scorerResults !== 176) {
            throw new RuntimeException('The dry run has unexpected corpus, configuration, attempt, or scorer coverage.');
        }

        return [
            'trials' => count($trials),
            'fixtures' => count($fixturePages),
            'pages' => array_sum($fixturePages),
            'attempts' => $attempts,
            'scorer_results' => $scorerResults,
        ];
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read [{$path}].");
        }

        return $this->object(json_decode($contents, true, flags: JSON_THROW_ON_ERROR), basename($path));
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $label): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException("The {$label} must be an object.");
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException("The {$label} must use string keys.");
            }

            $object[$key] = $item;
        }

        return $object;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("The {$label} must be a list.");
        }

        return $value;
    }

    private function string(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The {$label} must be a non-empty string.");
        }

        return $value;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }
}
