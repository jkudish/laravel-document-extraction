<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use LogicException;
use Pest\Evals\Scorers\Scorer;
use Pest\Expectation;

final class GroupingScorerRecorder
{
    public static function record(
        string $output,
        Scorer $scorer,
        ?string $expected = null,
        float $threshold = 1.0,
    ): void {
        $expectation = expect($output);
        $returned = $expectation->__call('toPassBenchmarkScorer', [$scorer, $threshold, $expected]);

        if (! $returned instanceof Expectation) {
            throw new LogicException('The benchmark scorer extension did not return the active expectation.');
        }
    }
}
