<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Jkudish\DocumentExtraction\Results\ExtractionError;

/**
 * @phpstan-type ValidatedGroup array{pages: list<int>, ambiguous: bool}
 * @phpstan-type ValidatedGrouping array{groups: list<ValidatedGroup>, errors: list<ExtractionError>, unassigned: list<int>}
 */
final readonly class PageGroupValidator
{
    /**
     * @param  array<string, mixed>  $detected
     * @param  list<int>  $selectedPages
     * @return ValidatedGrouping
     */
    public function validate(array $detected, array $selectedPages): array
    {
        $rawGroups = $detected['groups'] ?? null;

        if (! is_array($rawGroups) || ! array_is_list($rawGroups)) {
            return $this->failed($selectedPages, 'The detector returned an invalid page-group assignment.');
        }

        if ($rawGroups === []) {
            return $this->failed($selectedPages, 'Document detection returned no usable page groups.');
        }

        $selected = array_fill_keys($selectedPages, true);
        $candidates = [];
        $errors = [];
        $owners = [];
        $invalidClaims = [];

        foreach ($rawGroups as $index => $rawGroup) {
            if (! is_array($rawGroup) || ! is_array($rawGroup['pages'] ?? null) || ! is_bool($rawGroup['ambiguous'] ?? null)) {
                $errors[] = $this->invalid([]);

                continue;
            }

            $pages = $rawGroup['pages'];
            $normalized = [];
            $valid = $pages !== [] && array_is_list($pages);

            foreach ($pages as $page) {
                if (! is_int($page) || ! isset($selected[$page]) || isset($normalized[$page])) {
                    $valid = false;

                    continue;
                }

                $normalized[$page] = true;
            }

            $groupPages = array_keys($normalized);
            sort($groupPages, SORT_NUMERIC);

            if (! $valid) {
                $errors[] = $this->invalid($groupPages);

                foreach ($groupPages as $page) {
                    $invalidClaims[$page] = true;
                }

                continue;
            }

            $candidateIndex = count($candidates);
            $candidates[] = ['pages' => $groupPages, 'ambiguous' => $rawGroup['ambiguous'], 'overlap' => false];

            foreach ($groupPages as $page) {
                $owners[$page][] = $candidateIndex;
            }
        }

        foreach ($owners as $page => $indices) {
            if (count($indices) < 2) {
                continue;
            }

            foreach ($indices as $index) {
                $candidates[$index]['overlap'] = true;
            }

            $errors[] = new ExtractionError(
                'invalid_detection_assignment',
                'Detected document groups must not overlap.',
                [(int) $page],
            );
        }

        $groups = [];
        $assigned = [];

        foreach ($candidates as $candidate) {
            if ($candidate['overlap'] || array_intersect_key(array_fill_keys($candidate['pages'], true), $invalidClaims) !== []) {
                continue;
            }

            $groups[] = ['pages' => $candidate['pages'], 'ambiguous' => $candidate['ambiguous']];

            foreach ($candidate['pages'] as $page) {
                $assigned[$page] = true;
            }
        }

        $unassigned = array_values(array_filter(
            $selectedPages,
            static fn (int $page): bool => ! isset($assigned[$page]),
        ));

        if ($unassigned !== []) {
            $errors[] = new ExtractionError(
                'unassigned_pages',
                'Document detection did not assign every selected original page.',
                $unassigned,
            );
        }

        if ($groups === [] && $errors === []) {
            $errors[] = new ExtractionError(
                'detection_failed',
                'Document detection returned no usable page groups.',
                $selectedPages,
            );
        }

        return ['groups' => $groups, 'errors' => $errors, 'unassigned' => $unassigned];
    }

    /** @param list<int> $pages */
    private function invalid(array $pages): ExtractionError
    {
        return new ExtractionError(
            'invalid_detection_assignment',
            'The detector returned an invalid page-group assignment.',
            $pages,
        );
    }

    /**
     * @param  list<int>  $pages
     * @return ValidatedGrouping
     */
    private function failed(array $pages, string $message): array
    {
        return [
            'groups' => [],
            'errors' => [new ExtractionError('detection_failed', $message, $pages)],
            'unassigned' => $pages,
        ];
    }
}
