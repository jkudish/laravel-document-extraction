<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use JsonException;
use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;
use UnexpectedValueException;

final readonly class GroupingMetricScorer implements Scorer
{
    public function __construct(private GroupingMetric $metric) {}

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        try {
            $actual = self::object($output);
            $truth = self::object($expected ?? '{}');
            [$passed, $reasoning] = $this->evaluate($actual, $truth);
        } catch (JsonException|UnexpectedValueException) {
            $passed = false;
            $reasoning = 'The grouping result or expected truth was malformed.';
        }

        return new ScorerResult(
            score: $passed ? 1.0 : 0.0,
            reasoning: $reasoning,
            scorer: $this->metric->value,
        );
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $truth
     * @return array{bool, string}
     */
    private function evaluate(array $actual, array $truth): array
    {
        $actualGroups = self::groups($actual['groups'] ?? null);
        $expectedGroups = self::groups($truth['groups'] ?? null);
        $selectedPages = self::pages($actual['selected_pages'] ?? null);
        $expectedUnassigned = self::pages($truth['unassigned_pages'] ?? null);
        $actualUnassigned = self::pages($actual['unassigned_pages'] ?? null);
        $expectedAmbiguous = self::pages($truth['ambiguous_pages'] ?? null);
        $actualAmbiguous = self::pages($actual['ambiguous_pages'] ?? null);
        $errors = self::errors($actual['errors'] ?? null);
        $mergeErrors = self::mergeErrors($actualGroups, $expectedGroups, $selectedPages);
        $splitErrors = self::splitErrors($actualGroups, $expectedGroups, $selectedPages);
        $membershipFailures = self::errorCount($errors, 'invalid_detection_assignment');
        $schemaFailures = self::errorCount($errors, 'invalid_output')
            + self::detectionFailures($errors, retryable: false);
        $providerFailures = self::errorCount($errors, 'provider_failed')
            + self::detectionFailures($errors, retryable: true);
        $actualCoverage = self::coverage($selectedPages, $actualUnassigned);
        $expectedCoverage = self::coverage($selectedPages, $expectedUnassigned);

        return match ($this->metric) {
            GroupingMetric::ExactGroups => [
                self::canonicalGroups($actualGroups) === self::canonicalGroups($expectedGroups),
                sprintf('Detected %d group(s); expected %d exact group(s).', count($actualGroups), count($expectedGroups)),
            ],
            GroupingMetric::MergeErrors => [
                $mergeErrors === 0,
                sprintf('%d cross-document page pair(s) were merged.', $mergeErrors),
            ],
            GroupingMetric::SplitErrors => [
                $splitErrors === 0,
                sprintf('%d same-document page pair(s) were split.', $splitErrors),
            ],
            GroupingMetric::SelectedPageCoverage => [
                abs($actualCoverage - $expectedCoverage) < 0.000_000_1,
                sprintf('Assigned-page coverage was %.6f; expected %.6f.', $actualCoverage, $expectedCoverage),
            ],
            GroupingMetric::Ambiguity => [
                $actualAmbiguous === $expectedAmbiguous,
                sprintf('Ambiguous pages were [%s]; expected [%s].', implode(',', $actualAmbiguous), implode(',', $expectedAmbiguous)),
            ],
            GroupingMetric::UnassignedPages => [
                $actualUnassigned === $expectedUnassigned,
                sprintf('Unassigned pages were [%s]; expected [%s].', implode(',', $actualUnassigned), implode(',', $expectedUnassigned)),
            ],
            GroupingMetric::SchemaFailures => [
                $schemaFailures === 0,
                sprintf('%d schema or structured-output failure(s) were recorded.', $schemaFailures),
            ],
            GroupingMetric::MembershipFailures => [
                $membershipFailures === 0,
                sprintf('%d page-membership failure(s) were recorded.', $membershipFailures),
            ],
            GroupingMetric::ProviderFailures => [
                $providerFailures === 0,
                sprintf('%d provider failure(s) were recorded.', $providerFailures),
            ],
        };
    }

    /** @return array<string, mixed> */
    private static function object(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }

    /** @return list<list<int>> */
    private static function groups(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException('Grouping evidence must be a list.');
        }

        $groups = [];

        foreach ($value as $group) {
            $pages = self::pages($group);

            if ($pages === []) {
                throw new UnexpectedValueException('A detected group must contain at least one page.');
            }

            $groups[] = $pages;
        }

        return $groups;
    }

    /** @return list<int> */
    private static function pages(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException('Page evidence must be a list.');
        }

        foreach ($value as $page) {
            if (! is_int($page) || $page < 1) {
                throw new UnexpectedValueException('Page evidence must contain positive integers.');
            }
        }

        $pages = array_values(array_unique($value));

        if (count($pages) !== count($value)) {
            throw new UnexpectedValueException('Page evidence must not contain duplicates.');
        }

        sort($pages, SORT_NUMERIC);

        return $pages;
    }

    /** @return list<array{code: string, retryable: bool}> */
    private static function errors(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException('Error evidence must be a list.');
        }

        $errors = [];

        foreach ($value as $error) {
            if (! is_array($error) || ! is_string($error['code'] ?? null) || ! is_bool($error['retryable'] ?? null)) {
                throw new UnexpectedValueException('Error evidence must include a code and retryable flag.');
            }

            $errors[] = ['code' => $error['code'], 'retryable' => $error['retryable']];
        }

        return $errors;
    }

    /** @param list<list<int>> $groups
     * @return list<list<int>>
     */
    private static function canonicalGroups(array $groups): array
    {
        foreach ($groups as &$group) {
            sort($group, SORT_NUMERIC);
        }
        unset($group);

        usort($groups, static fn (array $left, array $right): int => implode(',', $left) <=> implode(',', $right));

        return $groups;
    }

    /**
     * @param  list<list<int>>  $actual
     * @param  list<list<int>>  $expected
     * @param  list<int>  $selectedPages
     */
    private static function mergeErrors(array $actual, array $expected, array $selectedPages): int
    {
        return self::pairErrors($actual, $expected, $selectedPages);
    }

    /**
     * @param  list<list<int>>  $actual
     * @param  list<list<int>>  $expected
     * @param  list<int>  $selectedPages
     */
    private static function splitErrors(array $actual, array $expected, array $selectedPages): int
    {
        return self::pairErrors($expected, $actual, $selectedPages);
    }

    /**
     * Count pairs owned together on the left but not together on the right.
     *
     * @param  list<list<int>>  $left
     * @param  list<list<int>>  $right
     * @param  list<int>  $selectedPages
     */
    private static function pairErrors(array $left, array $right, array $selectedPages): int
    {
        $leftOwners = self::owners($left);
        $rightOwners = self::owners($right);
        $errors = 0;

        foreach ($selectedPages as $position => $page) {
            foreach (array_slice($selectedPages, $position + 1) as $other) {
                $leftTogether = isset($leftOwners[$page], $leftOwners[$other]) && $leftOwners[$page] === $leftOwners[$other];
                $rightTogether = isset($rightOwners[$page], $rightOwners[$other]) && $rightOwners[$page] === $rightOwners[$other];

                if ($leftTogether && ! $rightTogether) {
                    $errors++;
                }
            }
        }

        return $errors;
    }

    /** @param list<list<int>> $groups
     * @return array<int, int>
     */
    private static function owners(array $groups): array
    {
        $owners = [];

        foreach ($groups as $owner => $group) {
            foreach ($group as $page) {
                $owners[$page] = $owner;
            }
        }

        return $owners;
    }

    /** @param list<int> $selectedPages
     * @param  list<int>  $unassignedPages
     */
    private static function coverage(array $selectedPages, array $unassignedPages): float
    {
        return $selectedPages === []
            ? 0.0
            : (count($selectedPages) - count(array_intersect($selectedPages, $unassignedPages))) / count($selectedPages);
    }

    /** @param list<array{code: string, retryable: bool}> $errors */
    private static function errorCount(array $errors, string $code): int
    {
        return count(array_filter($errors, static fn (array $error): bool => $error['code'] === $code));
    }

    /** @param list<array{code: string, retryable: bool}> $errors */
    private static function detectionFailures(array $errors, bool $retryable): int
    {
        return count(array_filter(
            $errors,
            static fn (array $error): bool => $error['code'] === 'detection_failed' && $error['retryable'] === $retryable,
        ));
    }
}
