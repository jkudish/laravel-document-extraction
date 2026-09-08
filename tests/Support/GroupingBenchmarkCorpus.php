<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use FilesystemIterator;
use Illuminate\Support\Collection;
use Jkudish\DocumentExtraction\AI\DocumentDetectionAgent;
use Jkudish\DocumentExtraction\AI\InlineSchemaAgent;
use Jkudish\DocumentExtraction\Results\CallRecord;
use Jkudish\DocumentExtraction\Results\DocumentResult;
use Jkudish\DocumentExtraction\Results\ExtractionError;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * @phpstan-type GroupingExpected array{groups: list<list<int>>, unassigned_pages: list<int>, ambiguous_pages: list<int>, unassigned_reasons: array<int, string>}
 * @phpstan-type GroupingFixture array{id: string, file: string, split: string, description: string, page_count: int, sha256: string, size: int, content: string, expected: GroupingExpected, runtime: array<string, string>}
 * @phpstan-type GroupingManifest array{schema_version: int, provenance: string, page_numbering: string, splits: array<string, string>, fixtures: list<array{id: string, file: string, split: string, description: string, page_count: int, sha256: string, size: int, content: string, expected: GroupingExpected}>}
 */
final class GroupingBenchmarkCorpus
{
    public const string CONTRACT = 'r5:c34ff11a71202534af076b45106169ef41cfb6e2ab9acd509f22834c97872a3b';

    /** @return GroupingFixture */
    public static function fixture(mixed $value): array
    {
        if (! is_array($value)
            || ! is_string($value['id'] ?? null)
            || ! is_string($value['file'] ?? null)
            || ! is_string($value['split'] ?? null)
            || ! is_string($value['description'] ?? null)
            || ! is_int($value['page_count'] ?? null)
            || ! is_string($value['sha256'] ?? null)
            || ! is_int($value['size'] ?? null)
            || ! is_string($value['content'] ?? null)
            || ! is_array($value['expected'] ?? null)
            || ! is_array($value['runtime'] ?? null)) {
            throw new RuntimeException('The grouping benchmark fixture is invalid.');
        }

        $expected = $value['expected'];

        return [
            'id' => $value['id'],
            'file' => $value['file'],
            'split' => $value['split'],
            'description' => $value['description'],
            'page_count' => $value['page_count'],
            'sha256' => $value['sha256'],
            'size' => $value['size'],
            'content' => $value['content'],
            'expected' => [
                'groups' => self::pageGroups($expected['groups'] ?? null),
                'unassigned_pages' => self::pages($expected['unassigned_pages'] ?? null),
                'ambiguous_pages' => self::pages($expected['ambiguous_pages'] ?? null),
                'unassigned_reasons' => self::reasons($expected['unassigned_reasons'] ?? null),
            ],
            'runtime' => self::strings($value['runtime']),
        ];
    }

    /** @return array<string, mixed> */
    public static function outputObject(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('The grouping benchmark output is invalid.');
        }

        $output = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException('The grouping benchmark output requires string keys.');
            }

            $output[$key] = $item;
        }

        return $output;
    }

    /** @return GroupingManifest */
    public static function manifest(): array
    {
        $contents = file_get_contents(self::fixturePath('manifest.json'));

        if (! is_string($contents)) {
            throw new RuntimeException('The grouping fixture manifest is unreadable.');
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)
            || ! is_int($decoded['schema_version'] ?? null)
            || ! is_string($decoded['provenance'] ?? null)
            || ! is_string($decoded['page_numbering'] ?? null)
            || ! is_array($decoded['splits'] ?? null)
            || ! is_array($decoded['fixtures'] ?? null)
            || ! array_is_list($decoded['fixtures'])) {
            throw new RuntimeException('The grouping fixture manifest is invalid.');
        }

        $fixtures = [];

        foreach ($decoded['fixtures'] as $rawFixture) {
            $fixture = self::fixture(is_array($rawFixture) ? [...$rawFixture, 'runtime' => []] : null);
            unset($fixture['runtime']);
            $fixtures[] = $fixture;
        }

        return [
            'schema_version' => $decoded['schema_version'],
            'provenance' => $decoded['provenance'],
            'page_numbering' => $decoded['page_numbering'],
            'splits' => self::strings($decoded['splits']),
            'fixtures' => $fixtures,
        ];
    }

    /** @return iterable<string, array{0: GroupingFixture}> */
    public static function fixtures(?string $runtimeSalt = null): iterable
    {
        $runtime = self::runtimeEvidence($runtimeSalt);

        foreach (self::manifest()['fixtures'] as $fixture) {
            yield $fixture['id'] => [[...$fixture, 'runtime' => $runtime]];
        }
    }

    public static function fixturePath(string $file): string
    {
        return dirname(__DIR__).'/Fixtures/Grouping/'.$file;
    }

    /** @param array{file: string, sha256: string, size: int} $fixture */
    public static function verifiedFixturePath(array $fixture): string
    {
        $root = realpath(dirname(__DIR__).'/Fixtures/Grouping');
        $path = self::fixturePath($fixture['file']);
        $resolved = realpath($path);

        clearstatcache(true, $path);

        if ($root === false
            || $fixture['file'] !== basename($fixture['file'])
            || is_link($path)
            || $resolved === false
            || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)
            || ! is_file($resolved)
            || filesize($resolved) !== $fixture['size']
            || ! hash_equals($fixture['sha256'], self::fileHash($resolved))) {
            throw new RuntimeException('The grouping fixture file does not match its approved manifest identity.');
        }

        return $resolved;
    }

    /** @return array<string, string> */
    public static function runtimeEvidence(?string $salt = null): array
    {
        return [
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'imagick_extension' => phpversion('imagick') ?: 'unavailable',
            'image_magick' => defined('Imagick::IMAGICK_EXTVER') ? \Imagick::IMAGICK_EXTVER : 'unavailable',
            'pdfinfo' => self::version('pdfinfo'),
            'pdfimages' => self::version('pdfimages'),
            'pdftoppm' => self::version('pdftoppm'),
            'pdftotext' => self::version('pdftotext'),
            'salt' => $salt ?? (getenv('LDE_GROUPING_RUNTIME_SALT') ?: ''),
        ];
    }

    /** @return array<string, mixed> */
    public static function scorecardContext(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            'contract' => self::CONTRACT,
            'corpus_sha256' => self::corpusFingerprint(),
            'manifest_sha256' => self::fileHash(self::fixturePath('manifest.json')),
            'composer_lock_sha256' => self::fileHash($root.'/composer.lock'),
            'extraction_config_sha256' => self::fileHash($root.'/config/extraction.php'),
            'runtime' => self::runtimeEvidence(),
        ];
    }

    /** @return list<string> */
    public static function dependencies(): array
    {
        return [
            'tests/Evals/GroupingBenchmarkTest.php',
            'tests/Support/GroupingAttemptScorer.php',
            'tests/Support/GroupingBenchmarkCorpus.php',
            'tests/Support/GroupingMetric.php',
            'tests/Support/GroupingMetricScorer.php',
            'tests/Support/GroupingScorerRecorder.php',
            'tests/Fixtures/Grouping/README.md',
            'tests/Fixtures/Grouping/manifest.json',
            'tests/Fixtures/Grouping/bundle-01.pdf',
            'tests/Fixtures/Grouping/bundle-02.pdf',
            'tests/Fixtures/Grouping/bundle-03.pdf',
            'tests/Fixtures/Grouping/bundle-04.pdf',
            'tests/Fixtures/Grouping/bundle-05.pdf',
            'tests/Fixtures/Grouping/bundle-06.pdf',
            'tests/Fixtures/Grouping/bundle-07.pdf',
            'tests/Fixtures/Grouping/bundle-08.pdf',
            'config/extraction.php',
            'composer.lock',
            'docs/evaluation-setup.md',
            ...self::packageSources(),
            'vendor/jkudish/pest-plugin-ai-benchmarks/src/Autoload.php',
            'vendor/jkudish/pest-plugin-ai-benchmarks/src/BenchmarkCall.php',
            'vendor/jkudish/pest-plugin-ai-benchmarks/src/ConfigurationScope.php',
            'vendor/jkudish/pest-plugin-ai-benchmarks/src/LaravelAi/BenchmarkAgentMiddleware.php',
            'vendor/jkudish/pest-plugin-ai-benchmarks/src/Plugin.php',
            'vendor/jkudish/pest-plugin-ai-benchmarks/src/Reporters/ExecutionRecorder.php',
            'vendor/jkudish/pest-plugin-ai-benchmarks/src/Runs/ReplayPayload.php',
            'vendor/jkudish/pest-plugin-ai-benchmarks/src/Runs/StableScorecardValidator.php',
            'vendor/laravel/ai/src/Gateway/FakeTextGateway.php',
            'vendor/pestphp/pest-plugin-evals/src/Autoload.php',
        ];
    }

    /** @param GroupingFixture $fixture */
    public static function fakeAgents(array $fixture): void
    {
        /** @param Collection<int, mixed> $attachments */
        $detect = function (
            string $prompt,
            Collection $attachments,
            TextProvider $provider,
            string $model,
        ) use ($fixture): StructuredTextResponse {
            $expectedPrompt = sprintf(
                'Identify logical document groups among the attached normalized pages. Use only these original physical page numbers: %s.',
                implode(', ', range(1, $fixture['page_count'])),
            );
            expect($prompt)->toBe($expectedPrompt)
                ->not->toContain($fixture['id'])
                ->not->toContain($fixture['split'])
                ->not->toContain('expected')
                ->and($attachments)->toHaveCount($fixture['page_count']);

            $groups = array_map(
                static fn (array $pages): array => ['pages' => $pages, 'ambiguous' => false],
                $fixture['expected']['groups'],
            );

            foreach ($fixture['expected']['ambiguous_pages'] as $page) {
                $groups[] = ['pages' => [$page], 'ambiguous' => true];
            }

            $structured = ['groups' => $groups];

            return new StructuredTextResponse(
                structured: $structured,
                text: json_encode($structured, JSON_THROW_ON_ERROR),
                usage: new Usage(promptTokens: 13, completionTokens: 5),
                meta: new Meta(provider: $provider->name(), model: $model),
            );
        };
        DocumentDetectionAgent::fake($detect)->preventStrayPrompts();

        /** @param Collection<int, mixed> $attachments */
        $extract = function (
            string $prompt,
            Collection $attachments,
            TextProvider $provider,
            string $model,
        ) use ($fixture): StructuredTextResponse {
            expect($prompt)->not->toContain($fixture['id'])
                ->not->toContain($fixture['split'])
                ->not->toContain('expected')
                ->and($attachments)->not->toBeEmpty();

            $structured = ['value' => 'offline extraction'];

            return new StructuredTextResponse(
                structured: $structured,
                text: json_encode($structured, JSON_THROW_ON_ERROR),
                usage: new Usage(promptTokens: 17, completionTokens: 9),
                meta: new Meta(provider: $provider->name(), model: $model),
            );
        };
        InlineSchemaAgent::fake($extract)->preventStrayPrompts();
    }

    /**
     * @param  GroupingFixture  $fixture
     * @return array<string, mixed>
     */
    public static function output(ExtractionResult $result, array $fixture): array
    {
        $documents = $result->documents->map(static fn (DocumentResult $document): array => [
            'pages' => $document->pages?->all() ?? [],
            'complete' => $document->complete(),
            'error' => $document->error?->toArray(),
        ])->all();
        $groups = array_values(array_map(
            static fn (array $document): array => $document['pages'],
            array_filter($documents, static fn (array $document): bool => $document['complete'] === true),
        ));
        $assigned = array_values(array_unique(array_merge(...($groups === [] ? [[]] : $groups))));
        sort($assigned, SORT_NUMERIC);
        $selectedPages = range(1, $fixture['page_count']);
        $unassignedPages = array_values(array_diff($selectedPages, $assigned));
        $errors = array_values($result->errors->map(static fn (ExtractionError $error): array => $error->toArray())->all());

        return [
            'fixture_id' => $fixture['id'],
            'source_sha256' => $result->sourceSha256,
            'page_count' => $result->pageCount,
            'selected_pages' => $selectedPages,
            'groups' => $groups,
            'unassigned_pages' => $unassignedPages,
            'ambiguous_pages' => self::errorPages($errors, 'ambiguous_detection'),
            'documents' => $documents,
            'errors' => $errors,
            'calls' => $result->calls->map(static fn (CallRecord $call): array => $call->toArray())->all(),
            'cost' => $result->cost->toArray(),
        ];
    }

    private static function corpusFingerprint(): string
    {
        $hashes = ['manifest.json' => self::fileHash(self::fixturePath('manifest.json'))];

        foreach (self::manifest()['fixtures'] as $fixture) {
            $hashes[$fixture['file']] = self::fileHash(self::fixturePath($fixture['file']));
        }

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private static function packageSources(): array
    {
        $root = dirname(__DIR__, 2);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root.'/src',
            FilesystemIterator::SKIP_DOTS,
        ));
        $sources = [];

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();

            if (! is_string($path)) {
                throw new RuntimeException('A package source dependency could not be resolved.');
            }

            $sources[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
        }

        sort($sources);

        return $sources;
    }

    private static function fileHash(string $path): string
    {
        $hash = hash_file('sha256', $path);

        return is_string($hash) ? $hash : throw new RuntimeException("Unable to hash [{$path}].");
    }

    private static function version(string $binary): string
    {
        $process = new Process([$binary, '-v']);
        $process->run();
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        if (! $process->isSuccessful() || $output === '') {
            return 'unavailable';
        }

        return strtok($output, "\r\n") ?: 'unavailable';
    }

    /** @return list<list<int>> */
    private static function pageGroups(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('The grouping benchmark fixture groups are invalid.');
        }

        return array_map(self::pages(...), $value);
    }

    /** @return list<int> */
    private static function pages(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('The grouping benchmark fixture pages are invalid.');
        }

        $pages = [];

        foreach ($value as $page) {
            if (! is_int($page)) {
                throw new RuntimeException('The grouping benchmark fixture page is invalid.');
            }

            $pages[] = $page;
        }

        return $pages;
    }

    /** @return array<int, string> */
    private static function reasons(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('The grouping benchmark fixture reasons are invalid.');
        }

        $reasons = [];

        foreach ($value as $page => $reason) {
            if (! is_int($page) || ! is_string($reason)) {
                throw new RuntimeException('The grouping benchmark fixture reason is invalid.');
            }

            $reasons[$page] = $reason;
        }

        return $reasons;
    }

    /** @return array<string, string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('The grouping benchmark runtime evidence is invalid.');
        }

        $strings = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_string($item)) {
                throw new RuntimeException('The grouping benchmark runtime evidence is invalid.');
            }

            $strings[$key] = $item;
        }

        return $strings;
    }

    /** @param list<array{code: string, message: string, pages: list<int>, path: ?string, retryable: bool}> $errors
     * @return list<int>
     */
    private static function errorPages(array $errors, string $code): array
    {
        $pages = [];

        foreach ($errors as $error) {
            if ($error['code'] === $code) {
                array_push($pages, ...$error['pages']);
            }
        }

        $pages = array_values(array_unique($pages));
        sort($pages, SORT_NUMERIC);

        return $pages;
    }
}
