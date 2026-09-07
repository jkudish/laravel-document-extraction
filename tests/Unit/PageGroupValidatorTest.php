<?php

declare(strict_types=1);

use Jkudish\DocumentExtraction\AI\PageGroupValidator;

it('accepts disjoint groups and reports selected pages omitted by the detector', function (): void {
    $result = (new PageGroupValidator)->validate([
        'groups' => [
            ['pages' => [4, 2], 'ambiguous' => false],
            ['pages' => [5], 'ambiguous' => true],
        ],
    ], [1, 2, 4, 5]);

    expect($result['groups'])->toBe([
        ['pages' => [2, 4], 'ambiguous' => false],
        ['pages' => [5], 'ambiguous' => true],
    ])->and($result['unassigned'])->toBe([1])
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0]->code)->toBe('unassigned_pages')
        ->and($result['errors'][0]->pages)->toBe([1]);
});

it('rejects structurally invalid assignments without discarding an independent usable group', function (array $groups, array $errorPages): void {
    $result = (new PageGroupValidator)->validate(['groups' => $groups], [1, 2, 3, 4]);

    expect($result['groups'])->toBe([['pages' => [4], 'ambiguous' => false]])
        ->and($result['errors'])->not->toBeEmpty()
        ->and($result['errors'][0]->code)->toBe('invalid_detection_assignment')
        ->and($result['errors'][0]->pages)->toBe($errorPages)
        ->and($result['unassigned'])->toBe([1, 2, 3]);
})->with([
    'duplicate within group' => [[
        ['pages' => [1, 1], 'ambiguous' => false],
        ['pages' => [4], 'ambiguous' => false],
    ], [1]],
    'out of selected range' => [[
        ['pages' => [2, 99], 'ambiguous' => false],
        ['pages' => [4], 'ambiguous' => false],
    ], [2]],
    'overlap invalidates both owners' => [[
        ['pages' => [1, 2], 'ambiguous' => false],
        ['pages' => [2, 3], 'ambiguous' => false],
        ['pages' => [4], 'ambiguous' => false],
    ], [2]],
]);

/** @param array<string, mixed> $output */
it('returns a detection failure and no fabricated group for malformed or empty output', function (array $output): void {
    /** @var array<string, mixed> $output */
    $result = (new PageGroupValidator)->validate($output, [1, 2]);

    expect($result['groups'])->toBe([])
        ->and($result['unassigned'])->toBe([1, 2])
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0]->code)->toBe('detection_failed');
})->with([
    'missing groups' => [[]],
    'non-list groups' => [['groups' => ['first' => ['pages' => [1], 'ambiguous' => false]]]],
    'empty groups' => [['groups' => []]],
]);
