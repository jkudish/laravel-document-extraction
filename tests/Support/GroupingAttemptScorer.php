<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use JsonException;
use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;

final class GroupingAttemptScorer implements Scorer
{
    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new ScorerResult(0.0, 'The grouping result was not valid JSON.', 'native-attempt-integrity');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return new ScorerResult(0.0, 'The grouping result was not a JSON object.', 'native-attempt-integrity');
        }

        $calls = $decoded['calls'] ?? null;
        $cost = $decoded['cost'] ?? null;

        if (! is_array($calls) || ! array_is_list($calls) || $calls === [] || ! is_array($cost)) {
            return new ScorerResult(0.0, 'Call and cost evidence was missing.', 'native-attempt-integrity');
        }

        $references = [];
        $ordinals = [];
        $valid = true;

        foreach ($calls as $call) {
            if (! is_array($call)
                || ! is_string($call['reference'] ?? null)
                || ! is_int($call['ordinal'] ?? null)
                || ($call['mode'] ?? null) !== 'simulated'
                || ($call['cost_quote'] ?? null) !== null) {
                $valid = false;

                continue;
            }

            $references[] = $call['reference'];
            $ordinals[] = $call['ordinal'];
        }

        $expectedOrdinals = range(1, count($calls));
        $unpriced = $cost['unpriced_calls'] ?? null;
        $known = $cost['known_by_currency'] ?? null;
        $valid = $valid
            && count(array_unique($references)) === count($references)
            && $ordinals === $expectedOrdinals
            && is_array($unpriced)
            && array_values($unpriced) === $references
            && is_array($known)
            && $known === []
            && ($cost['complete'] ?? null) === false
            && ($cost['mode'] ?? null) === 'simulated';

        return new ScorerResult(
            score: $valid ? 1.0 : 0.0,
            reasoning: $valid
                ? sprintf('%d unique simulated native attempt(s) retained without duplicate aggregate pricing.', count($calls))
                : 'Native call identity or simulated pricing evidence was inconsistent.',
            scorer: 'native-attempt-integrity',
        );
    }
}
