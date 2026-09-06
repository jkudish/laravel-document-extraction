<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Exceptions\ExtractionException;
use Jkudish\DocumentExtraction\Exceptions\ProcessingUnavailableException;
use Jkudish\DocumentExtraction\Results\PageResult;
use Jkudish\DocumentExtraction\Tests\Support\RecordingPreparedExtraction;

function pdfFixture(string $name): string
{
    return dirname(__DIR__).'/Fixtures/Pdf/'.$name;
}

function imageFixture(string $name): string
{
    return dirname(__DIR__).'/Fixtures/Images/'.$name;
}

function preparedRecorder(): RecordingPreparedExtraction
{
    return new RecordingPreparedExtraction(
        app(Repository::class),
        app(FilesystemFactory::class),
        app(Container::class),
    );
}

it('returns bounded deterministic UTF-8 text without a provider for every text family', function (string $mime, string $source, string $expected): void {
    $result = app(DocumentExtraction::class)->fromString($source, $mime)->text();

    expect($result->text)->toBe($expected)
        ->and($result->pageCount)->toBeNull()
        ->and($result->pages)->toBeEmpty()
        ->and($result->calls)->toBeEmpty()
        ->and($result->complete())->toBeTrue();
})->with([
    'plain' => ['text/plain', "line one\r\nline two", "line one\nline two"],
    'csv' => ['text/csv', "name,total\r\nAcme,12.30", "name,total\nAcme,12.30"],
    'html' => ['text/html', '<h1>Invoice</h1><p>Total 12.30</p>', '<h1>Invoice</h1><p>Total 12.30</p>'],
    'json' => ['application/json', '{"total":"12.30"}', '{"total":"12.30"}'],
    'xml' => ['application/xml', '<?xml version="1.0"?><invoice><total>12.30</total></invoice>', '<?xml version="1.0"?><invoice><total>12.30</total></invoice>'],
]);

it('does not let text-family hints bypass JSON or XML syntax and entity validation', function (string $mime, string $source, string $code): void {
    try {
        app(DocumentExtraction::class)->fromString($source, $mime)->withoutAi()->text();
        throw new RuntimeException('Expected invalid hinted syntax to fail.');
    } catch (ExtractionException $exception) {
        expect($exception->errorCode)->toBe($code);
    }
})->with([
    'misleading JSON hint' => ['application/json', 'plain but not JSON', 'invalid_json'],
    'misleading XML hint' => ['application/xml', '<invoice>', 'invalid_xml'],
    'external XML entity' => ['application/xml', '<!DOCTYPE x SYSTEM "https://example.invalid/external.dtd"><x/>', 'unsafe_xml'],
]);

it('rejects invalid UTF-8 instead of returning lossy direct text', function (): void {
    $source = str_repeat('text ', 200)."\xFF";

    try {
        app(DocumentExtraction::class)->fromString($source, 'text/plain')->withoutAi()->text();
        throw new RuntimeException('Expected invalid UTF-8 to fail.');
    } catch (ExtractionException $exception) {
        expect($exception->errorCode)->toBe('invalid_text');
    }
});

it('treats HTML URLs and scripts as inert source text without fetching them', function (): void {
    $source = '<script>fetch("https://example.invalid/private")</script><img src="https://example.invalid/pixel">';

    $result = app(DocumentExtraction::class)->fromString($source, 'text/html')->withoutAi()->text();

    expect($result->text)->toBe($source)
        ->and($result->calls)->toBeEmpty();
});

it('enforces the retained output limit before returning direct text', function (): void {
    config()->set('extraction.limits.retained_output_bytes', 8);

    try {
        app(DocumentExtraction::class)->fromString('123456789', 'text/plain')->withoutAi()->text();
        throw new RuntimeException('Expected direct text to exceed the output limit.');
    } catch (ExtractionException $exception) {
        expect($exception->errorCode)->toBe('output_limit_exceeded');
    }
});

it('enforces the aggregate output limit while Spatie text capture stays inside the bounded worker', function (): void {
    config()->set('extraction.limits.retained_output_bytes', 20_000);
    $source = pdfFixture('dense-letter.pdf');
    $sourceHash = hash_file('sha256', $source);

    try {
        app(DocumentExtraction::class)
            ->fromPath($source)
            ->pages([1])
            ->withoutAi()
            ->text();
        throw new RuntimeException('Expected PDF text to exceed the output limit.');
    } catch (ExtractionException $exception) {
        expect($exception->errorCode)->toBe('output_limit_exceeded')
            ->and(hash_file('sha256', $source))->toBe($sourceHash);
    }
});

it('extracts PDF text per selected original page without losing alignment', function (): void {
    $result = app(DocumentExtraction::class)
        ->fromPath(pdfFixture('multipage.pdf'))
        ->pages([4, 2])
        ->withoutAi()
        ->text();
    $page2 = $result->pages->get(0);
    $page4 = $result->pages->get(1);
    assert($page2 instanceof PageResult);
    assert($page4 instanceof PageResult);

    expect($result->pageCount)->toBe(5)
        ->and($result->pages->pluck('page')->all())->toBe([2, 4])
        ->and($page2->text)->toContain('PAGE 2 OF 5 MARKER BRAVO')
        ->and($page4->text)->toContain('PAGE 4 OF 5 MARKER BRAVO')
        ->and($result->text)->toContain('PAGE 2 OF 5 MARKER BRAVO')
        ->and($result->text)->toContain('PAGE 4 OF 5 MARKER BRAVO')
        ->and($result->complete())->toBeTrue();
});

it('handles hostile caller filenames through a private absolute snapshot path', function (string $fixture): void {
    $result = app(DocumentExtraction::class)
        ->fromPath(pdfFixture($fixture))
        ->withoutAi()
        ->text();

    expect($result->text)->toContain('TEXT ONLY DOCUMENT MARKER ALPHA ONE')
        ->and($result->complete())->toBeTrue();
})->with([
    'option-like basename' => '-optionish.pdf',
    'shell metacharacters' => 'argv/evil name;$(touch LDE_PWNED)&.pdf',
]);

it('preserves blank-page positions and reports their OCR coverage explicitly', function (string $fixture, int $unprocessedPage): void {
    $result = app(DocumentExtraction::class)
        ->fromPath(pdfFixture($fixture))
        ->withoutAi()
        ->text();
    $page = $result->pages->get($unprocessedPage - 1);
    assert($page instanceof PageResult);

    expect($result->pageCount)->toBe(3)
        ->and($result->pages->pluck('page')->all())->toBe([1, 2, 3])
        ->and($page->complete())->toBeFalse()
        ->and($page->error?->code)->toBe('ocr_required')
        ->and($result->errors->first()?->pages)->toBe([$unprocessedPage])
        ->and($result->complete())->toBeFalse();
})->with([
    'leading blank' => ['blank-leading.pdf', 1],
    'interior blank' => ['blank-interior.pdf', 2],
    'trailing blank' => ['blank-trailing.pdf', 3],
]);

it('routes raster mixed vector-only and apparently blank PDF pages conservatively', function (string $fixture, bool $retainsText): void {
    $result = app(DocumentExtraction::class)
        ->fromPath(pdfFixture($fixture))
        ->withoutAi()
        ->text();
    $page = $result->pages->first();
    assert($page instanceof PageResult);

    expect($result->pages)->toHaveCount(1)
        ->and($page->complete())->toBeFalse()
        ->and($page->error?->code)->toBe('ocr_required')
        ->and($result->text !== null)->toBe($retainsText)
        ->and($result->complete())->toBeFalse();
})->with([
    'raster' => ['raster.pdf', false],
    'mixed' => ['mixed.pdf', true],
    'vector only' => ['vector-only.pdf', false],
    'blank' => ['blank.pdf', false],
]);

it('renders and validates visual PDF pages for the next AI stage then cleans them', function (string $fixture): void {
    $recorder = preparedRecorder();
    $sourceHash = hash_file('sha256', pdfFixture($fixture));

    expect(fn () => $recorder->fromPath(pdfFixture($fixture))->text())
        ->toThrow(ProcessingUnavailableException::class, 'OCR execution');

    $record = $recorder->preparations[0];
    expect($record['sourceSha256'])->toBe($sourceHash)
        ->and($record['visuals'])->toHaveCount(1)
        ->and($record['visuals'][0]['mime'])->toBe('image/png')
        ->and($record['visuals'][0]['width'])->toBe(1275)
        ->and($record['visuals'][0]['height'])->toBe(1650)
        ->and($record['visuals'][0]['bytes'])->toBeGreaterThan(0)
        ->and(is_file($record['visuals'][0]['path']))->toBeFalse()
        ->and(hash_file('sha256', pdfFixture($fixture)))->toBe($sourceHash);
})->with(['raster.pdf', 'mixed.pdf', 'vector-only.pdf', 'blank.pdf']);

it('prepares every selected PDF page visually for structured extraction', function (): void {
    $recorder = preparedRecorder();

    expect(fn () => $recorder
        ->fromPath(pdfFixture('multipage.pdf'))
        ->pages([2, 4])
        ->schema(fn (JsonSchema $schema) => ['value' => $schema->string()->required()])
        ->extract())
        ->toThrow(ProcessingUnavailableException::class, 'Structured AI extraction');

    $record = $recorder->preparations[0];
    expect($record['prepared']->selectedPages)->toBe([2, 4])
        ->and($record['visuals'])->toHaveCount(2)
        ->and(array_column($record['visuals'], 'mime'))->each->toBe('image/png')
        ->and($record['prepared']->inlineAttachmentBytes())->toBeGreaterThan(0);

    foreach ($record['visuals'] as $visual) {
        expect(is_file($visual['path']))->toBeFalse();
    }
});

it('rejects encrypted malformed oversized and impossible PDF inputs before unsafe processing', function (string $fixture, string $code): void {
    try {
        app(DocumentExtraction::class)->fromPath(pdfFixture($fixture))->withoutAi()->text();
        throw new RuntimeException('Expected the unsafe PDF to fail.');
    } catch (ExtractionException $exception) {
        expect($exception->errorCode)->toBe($code);
    }
})->with([
    'empty user password encryption' => ['encrypted-empty-user.pdf', 'encrypted_pdf'],
    'user password encryption' => ['encrypted-user.pdf', 'encrypted_pdf'],
    'truncated' => ['malformed-truncated.pdf', 'invalid_pdf'],
    'garbage' => ['malformed-garbage.pdf', 'invalid_pdf'],
    '101 pages' => ['pages-101.pdf', 'page_limit_exceeded'],
]);

it('applies the decoded pixel limit only when a PDF page must be rendered', function (): void {
    $withoutAi = app(DocumentExtraction::class)
        ->fromPath(pdfFixture('bigpage-text.pdf'))
        ->withoutAi()
        ->text();
    $page = $withoutAi->pages->first();
    assert($page instanceof PageResult);

    expect($withoutAi->pages)->toHaveCount(1)
        ->and($page->complete())->toBeTrue()
        ->and($withoutAi->text)->not->toBeNull();

    try {
        app(DocumentExtraction::class)
            ->fromPath(pdfFixture('bigpage-text.pdf'))
            ->schema(fn (JsonSchema $schema) => ['value' => $schema->string()->required()])
            ->extract();
        throw new RuntimeException('Expected the oversized raster render to fail.');
    } catch (ExtractionException $exception) {
        expect($exception->errorCode)->toBe('pixel_limit_exceeded');
    }
});

it('contains the compressed PDF bomb in an OS-limited isolated worker', function (): void {
    config()->set('extraction.preparation.native_memory_bytes', 268_435_456);
    config()->set('extraction.preparation.php_memory_bytes', 134_217_728);

    try {
        app(DocumentExtraction::class)->fromPath(pdfFixture('bomb-stream.pdf'))->withoutAi()->text();
        throw new RuntimeException('Expected the bounded worker to reject the bomb.');
    } catch (ExtractionException $exception) {
        expect($exception->errorCode)->toBeIn(['resource_limit_exceeded', 'preparation_failed']);
    }
})->group('resource-intensive');

it('does not pass ambient credentials to the isolated Spatie or native process', function (): void {
    $fake = sys_get_temp_dir().'/lde-fake-pdftotext-'.bin2hex(random_bytes(6));
    file_put_contents($fake, "#!/usr/bin/env php\n<?php echo getenv('LDE_WORKER_SECRET') ?: '';\n");
    chmod($fake, 0700);
    putenv('LDE_WORKER_SECRET=must-not-reach-worker');
    config()->set('extraction.preparation.binaries.pdftotext', $fake);

    try {
        $result = app(DocumentExtraction::class)->fromPath(pdfFixture('text-only.pdf'))->withoutAi()->text();
        $page = $result->pages->first();
        assert($page instanceof PageResult);

        expect($result->text)->toBeNull()
            ->and($page->error?->code)->toBe('ocr_required');
    } finally {
        putenv('LDE_WORKER_SECRET');
        @unlink($fake);
    }
});

it('normalizes every advertised raster codec through the Illuminate Image worker', function (string $fixture, int $pages, int $width, int $height): void {
    $recorder = preparedRecorder();
    $sourceHash = hash_file('sha256', imageFixture($fixture));

    expect(fn () => $recorder->fromPath(imageFixture($fixture))->text())
        ->toThrow(ProcessingUnavailableException::class, 'OCR execution');

    $record = $recorder->preparations[0];
    expect($record['sourceSha256'])->toBe($sourceHash)
        ->and($record['prepared']->pageCount)->toBe($pages)
        ->and($record['visuals'])->toHaveCount($pages)
        ->and(hash_file('sha256', imageFixture($fixture)))->toBe($sourceHash);

    foreach ($record['visuals'] as $visual) {
        expect($visual['mime'])->toBe('image/png')
            ->and($visual['width'])->toBe($width)
            ->and($visual['height'])->toBe($height)
            ->and($visual['bytes'])->toBeGreaterThan(0)
            ->and(is_file($visual['path']))->toBeFalse();
    }
})->with([
    'JPEG' => ['sample.jpg', 1, 4, 3],
    'PNG' => ['sample.png', 1, 4, 3],
    'WebP' => ['sample.webp', 1, 4, 3],
    'TIFF pages' => ['multipage.tiff', 2, 4, 3],
    'HEIC' => ['sample.heic', 1, 4, 3],
    'HEIF' => ['sample.heif', 1, 4, 3],
    'BMP' => ['sample.bmp', 1, 4, 3],
    'AVIF' => ['sample.avif', 1, 4, 3],
    'single frame GIF' => ['sample.gif', 1, 4, 3],
    'EXIF orientation' => ['oriented.jpg', 1, 2, 3],
]);

it('rejects animated non-TIFF images instead of silently selecting a frame', function (): void {
    try {
        app(DocumentExtraction::class)->fromPath(imageFixture('animated.gif'))->withoutAi()->text();
        throw new RuntimeException('Expected animated GIF rejection.');
    } catch (ExtractionException $exception) {
        expect($exception->errorCode)->toBe('animated_image');
    }
});

it('rejects oversized raster dimensions in the isolated worker before decoding pixels', function (): void {
    $source = imageFixture('bomb.png');
    $sourceHash = hash_file('sha256', $source);

    try {
        app(DocumentExtraction::class)->fromPath($source)->withoutAi()->text();
        throw new RuntimeException('Expected the oversized raster to fail.');
    } catch (ExtractionException $exception) {
        expect($exception->errorCode)->toBe('pixel_limit_exceeded')
            ->and(hash_file('sha256', $source))->toBe($sourceHash);
    }
});

it('returns truthful unprocessed image coverage when AI is disabled', function (): void {
    $result = app(DocumentExtraction::class)
        ->fromPath(imageFixture('multipage.tiff'))
        ->pages([2])
        ->withoutAi()
        ->text();

    expect($result->pageCount)->toBe(2)
        ->and($result->pages->pluck('page')->all())->toBe([2])
        ->and($result->text)->toBeNull()
        ->and($result->errors->first()?->pages)->toBe([2])
        ->and($result->complete())->toBeFalse();
});

it('rejects selected pages beyond the inspected physical page count', function (): void {
    expect(fn () => app(DocumentExtraction::class)
        ->fromPath(imageFixture('sample.png'))
        ->pages([2])
        ->withoutAi()
        ->text())
        ->toThrow(ExtractionException::class, 'selected page');
});

it('enforces the active temporary byte budget and cleans every owned derivative on failure', function (): void {
    $source = pdfFixture('raster.pdf');
    config()->set('extraction.limits.temporary_bytes', filesize($source) + 100);
    $before = glob(sys_get_temp_dir().'/laravel-document-extraction-*') ?: [];

    expect(fn () => app(DocumentExtraction::class)->fromPath($source)->text())
        ->toThrow(ExtractionException::class);

    expect(glob(sys_get_temp_dir().'/laravel-document-extraction-*') ?: [])->toBe($before);
});
