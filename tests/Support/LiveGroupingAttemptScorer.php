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
        $route = $decoded['openrouter_route'] ?? null;

        if (! is_array($calls)
            || ! array_is_list($calls)
            || ! is_array($cost)
            || ! is_array($route)
            || array_is_list($route)) {
            return $this->result(false, 'Call, cost, or route evidence was missing.');
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
                $model = is_array($requested) && is_string($requested['model'] ?? null)
                    ? LiveGroupingModels::find($requested['model'])
                    : null;

                if (! is_array($requested)
                    || ($requested['provider'] ?? null) !== 'openrouter'
                    || ! is_string($requested['model'] ?? null)
                    || ! is_array($effective)
                    || ($effective['provider'] ?? null) !== 'openrouter'
                    || ! is_array($model)
                    || ! in_array($effective['model'] ?? null, [$model['id'], $model['canonical']], true)
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
            $requestedConfiguration = is_string($requestedModel)
                ? LiveGroupingModels::find($requestedModel)
                : null;
            $valid = $valid
                && count($calls) >= 1
                && count($liveCosts) === 1
                && count(array_unique($references)) === count($references)
                && is_string($known)
                && BigDecimal::of($known)->isEqualTo($summed)
                && ($cost['unpriced_calls'] ?? null) === $simulatedReferences
                && ($cost['complete'] ?? null) === ! $hasSimulatedCalls
                && ($cost['mode'] ?? null) === ($hasSimulatedCalls ? 'mixed' : 'live')
                && is_array($requestedConfiguration)
                && in_array($route['model'] ?? null, [
                    $requestedConfiguration['id'],
                    $requestedConfiguration['canonical'],
                ], true)
                && ($route['provider_name'] ?? null) === $requestedConfiguration['route']['provider_name']
                && ($route['data_region'] ?? null) === $requestedConfiguration['route']['data_region']
                && ($route['service_tier'] ?? null) === $requestedConfiguration['route']['service_tier']
                && ($route['provider_attempts'] ?? null) === 1;
        } catch (Throwable) {
            $valid = false;
        }

        return $this->result(
            $valid,
            $valid
                ? sprintf(
                    'One live detector used the approved route and %d simulated extraction attempt(s) were attributed exactly once. Output SHA-256: %s.',
                    count($simulatedReferences),
                    hash('sha256', $output),
                )
                : 'Live detector route or simulated extraction pricing evidence was inconsistent.',
            $valid ? 'live-detector-attempt-integrity@sha256:'.hash('sha256', $output) : null,
        );
    }

    private function result(bool $passed, string $reasoning, ?string $scorer = null): ScorerResult
    {
        return new ScorerResult(
            score: $passed ? 1.0 : 0.0,
            reasoning: $reasoning,
            scorer: $scorer ?? 'live-detector-attempt-integrity',
        );
    }
}
