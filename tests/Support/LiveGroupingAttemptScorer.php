<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use Brick\Math\BigDecimal;
use JsonException;
use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use Throwable;

final class LiveGroupingAttemptScorer implements Scorer
{
    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->result(false, 'The grouping result was not valid JSON.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return $this->result(false, 'The grouping result was not a JSON object.');
        }

        $calls = $decoded['calls'] ?? null;
        $cost = $decoded['cost'] ?? null;

        if (! is_array($calls) || ! array_is_list($calls) || ! is_array($cost)) {
            return $this->result(false, 'Call and cost evidence was missing.');
        }

        $references = [];
        $liveCosts = [];
        $simulatedReferences = [];
        $valid = true;
        $requestedModel = null;

        try {
            foreach ($calls as $index => $call) {
                if (! is_array($call)
                    || ! is_string($call['reference'] ?? null)
                    || ($call['ordinal'] ?? null) !== $index + 1) {
                    $valid = false;

                    continue;
                }

                $references[] = $call['reference'];
                $requested = $call['requested_identity'] ?? null;
                $effective = $call['effective_identity'] ?? null;
                $costQuote = $call['cost_quote'] ?? null;

                if (! is_array($requested)
                    || ($requested['provider'] ?? null) !== 'openrouter'
                    || ! is_string($requested['model'] ?? null)
                    || ! is_array($effective)
                    || ($effective['provider'] ?? null) !== 'openrouter'
                    || ! is_string($effective['model'] ?? null)
                    || ($requestedModel !== null && $requested['model'] !== $requestedModel)) {
                    $valid = false;
                }

                $requestedModel ??= is_array($requested) && is_string($requested['model'] ?? null)
                    ? $requested['model']
                    : null;

                if ($index === 0
                    && ($call['stage'] ?? null) === 'detection'
                    && ($call['mode'] ?? null) === 'live'
                    && is_array($costQuote)) {
                    $money = $costQuote['cost'] ?? null;
                    $amount = is_array($money) ? ($money['amount'] ?? null) : null;
                    $currency = is_array($money) ? ($money['currency'] ?? null) : null;

                    if (! is_string($amount) || $currency !== 'USD') {
                        $valid = false;
                    } else {
                        $liveCosts[] = BigDecimal::of($amount);
                    }
                } elseif ($index > 0
                    && ($call['stage'] ?? null) === 'extraction'
                    && ($call['mode'] ?? null) === 'simulated'
                    && $costQuote === null) {
                    $simulatedReferences[] = $call['reference'];
                } else {
                    $valid = false;
                }
            }

            $knownByCurrency = $cost['known_by_currency'] ?? null;
            $usd = is_array($knownByCurrency) ? ($knownByCurrency['USD'] ?? null) : null;
            $known = is_array($usd) ? ($usd['amount'] ?? null) : null;
            $summed = array_reduce(
                $liveCosts,
                static fn (BigDecimal $sum, BigDecimal $amount): BigDecimal => $sum->plus($amount),
                BigDecimal::zero(),
            );
            $hasSimulatedCalls = $simulatedReferences !== [];
            $valid = $valid
                && count($calls) >= 1
                && count($liveCosts) === 1
                && count(array_unique($references)) === count($references)
                && is_string($known)
                && BigDecimal::of($known)->isEqualTo($summed)
                && ($cost['unpriced_calls'] ?? null) === $simulatedReferences
                && ($cost['complete'] ?? null) === ! $hasSimulatedCalls
                && ($cost['mode'] ?? null) === ($hasSimulatedCalls ? 'mixed' : 'live');
        } catch (Throwable) {
            $valid = false;
        }

        return $this->result(
            $valid,
            $valid
                ? sprintf('One live detector and %d simulated extraction attempt(s) were attributed exactly once.', count($simulatedReferences))
                : 'Live detector or simulated extraction pricing evidence was inconsistent.',
        );
    }

    private function result(bool $passed, string $reasoning): ScorerResult
    {
        return new ScorerResult(
            score: $passed ? 1.0 : 0.0,
            reasoning: $reasoning,
            scorer: 'live-detector-attempt-integrity',
        );
    }
}
