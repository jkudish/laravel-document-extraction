<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Exceptions\ProcessingUnavailableException;
use Jkudish\DocumentExtraction\Tests\Support\RecordingPreparedExtraction;

function groupingFixturePath(string $file = ''): string
{
    return dirname(__DIR__).'/Fixtures/Grouping'.($file === '' ? '' : '/'.$file);
}

/** @return array{schema_version: int, provenance: string, page_numbering: string, splits: array<string, string>, fixtures: list<array{id: string, file: string, split: string, description: string, page_count: int, sha256: string, size: int, content: string, expected: array{groups: list<list<int>>, unassigned_pages: list<int>, ambiguous_pages: list<int>, unassigned_reasons: array<int, string>}}>} */
function groupingManifest(): array
{
    $contents = file_get_contents(groupingFixturePath('manifest.json'));

    if (! is_string($contents)) {
        throw new RuntimeException('Grouping fixture manifest is unreadable.');
    }

    /** @var array{schema_version: int, provenance: string, page_numbering: string, splits: array<string, string>, fixtures: list<array{id: string, file: string, split: string, description: string, page_count: int, sha256: string, size: int, content: string, expected: array{groups: list<list<int>>, unassigned_pages: list<int>, ambiguous_pages: list<int>, unassigned_reasons: array<int, string>}}>} $manifest */
    $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    return $manifest;
}

/** @return iterable<string, array{array{id: string, file: string, split: string, description: string, page_count: int, sha256: string, size: int, content: string, expected: array{groups: list<list<int>>, unassigned_pages: list<int>, ambiguous_pages: list<int>, unassigned_reasons: array<int, string>}}}> */
function groupingFixtures(): iterable
{
    foreach (groupingManifest()['fixtures'] as $fixture) {
        yield $fixture['id'] => [$fixture];
    }
}

it('defines eight synthetic fixtures with complete disjoint and conservative truth', function (): void {
    $manifest = groupingManifest();

    expect($manifest['schema_version'])->toBe(1)
        ->and($manifest['provenance'])->toContain('Entirely synthetic')
        ->and($manifest['page_numbering'])->toContain('One-based')
        ->and($manifest['fixtures'])->toHaveCount(8)
        ->and(array_count_values(array_column($manifest['fixtures'], 'split')))->toBe([
            'prompt-example' => 5,
            'holdout' => 3,
        ]);

    foreach ($manifest['fixtures'] as $fixture) {
        $assigned = [];

        foreach ($fixture['expected']['groups'] as $group) {
            expect($group)->not->toBeEmpty();
            $assigned = [...$assigned, ...$group];
        }

        $allPages = [...$assigned, ...$fixture['expected']['unassigned_pages']];
        $uniquePages = array_values(array_unique($allPages));
        sort($allPages, SORT_NUMERIC);
        sort($uniquePages, SORT_NUMERIC);

        expect($uniquePages)->toBe($allPages, "{$fixture['id']} assigns a physical page more than once")
            ->and($allPages)->toBe(range(1, $fixture['page_count']), "{$fixture['id']} must account for every physical page")
            ->and(array_diff($fixture['expected']['ambiguous_pages'], $fixture['expected']['unassigned_pages']))->toBeEmpty();

        foreach ($fixture['expected']['unassigned_pages'] as $page) {
            expect($fixture['expected']['unassigned_reasons'][$page] ?? '')
                ->not->toBeEmpty("{$fixture['id']} must explain every unassigned page");
        }
    }
});

it('matches committed PDF bytes and native page inventory', function (array $fixture): void {
    /** @var array{file: string, page_count: int, sha256: string, size: int, content: string} $fixture */
    $path = groupingFixturePath($fixture['file']);
    $result = app(DocumentExtraction::class)
        ->fromPath($path)
        ->withoutAi()
        ->text();

    expect(is_file($path))->toBeTrue()
        ->and(filesize($path))->toBe($fixture['size'])
        ->and(hash_file('sha256', $path))->toBe($fixture['sha256'])
        ->and($result->pageCount)->toBe($fixture['page_count'])
        ->and($result->pages->pluck('page')->all())->toBe(range(1, $fixture['page_count']))
        ->and($result->calls)->toBeEmpty();

    if ($fixture['content'] === 'raster-only') {
        expect($result->text)->toBeNull()
            ->and($result->complete())->toBeFalse();
    } else {
        expect($result->text)->not->toBeNull();
    }
})->with(groupingFixtures());

it('prepares every fixture page as visual content for the later grouping stage and cleans derivatives', function (array $fixture): void {
    /** @var array{file: string, page_count: int, sha256: string} $fixture */
    $recorder = new RecordingPreparedExtraction(
        app(Repository::class),
        app(FilesystemFactory::class),
        app(Container::class),
    );
    $path = groupingFixturePath($fixture['file']);

    expect(fn () => $recorder
        ->fromPath($path)
        ->schema(fn (JsonSchema $schema) => ['value' => $schema->string()->required()])
        ->extract())
        ->toThrow(ProcessingUnavailableException::class, 'Structured AI extraction');

    $record = $recorder->preparations[0];
    expect($record['sourceSha256'])->toBe($fixture['sha256'])
        ->and($record['prepared']->pageCount)->toBe($fixture['page_count'])
        ->and($record['prepared']->selectedPages)->toBe(range(1, $fixture['page_count']))
        ->and($record['visuals'])->toHaveCount($fixture['page_count'])
        ->and(array_column($record['visuals'], 'mime'))->each->toBe('image/png');

    foreach ($record['visuals'] as $visual) {
        expect($visual['width'])->toBe(1275)
            ->and($visual['height'])->toBe(1650)
            ->and($visual['bytes'])->toBeGreaterThan(0)
            ->and(is_file($visual['path']))->toBeFalse();
    }
})->with(groupingFixtures());
