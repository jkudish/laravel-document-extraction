<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Image as NativeImage;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\Facades\Extraction;

it('boots the package provider and exposes the complete benchmark configuration', function (): void {
    recordTiaExecution('config');

    expect(app()->bound(DocumentExtraction::class))->toBeTrue()
        ->and(app('document-extraction') === app(DocumentExtraction::class))->toBeTrue()
        ->and(Extraction::getFacadeRoot() === app(DocumentExtraction::class))->toBeTrue()
        ->and(config('extraction'))->toMatchArray([
            'provider' => null,
            'model' => null,
            'timeout' => 120,
            'options' => [],
            'middleware' => [],
            'ocr' => ['provider' => null, 'model' => null, 'options' => []],
            'detection' => ['provider' => null, 'model' => null, 'options' => []],
            'preparation' => [
                'render_dpi' => 150,
                'native_memory_bytes' => 1_073_741_824,
                'php_memory_bytes' => 402_653_184,
                'binaries' => [
                    'pdfinfo' => 'pdfinfo',
                    'pdfimages' => 'pdfimages',
                    'pdftoppm' => 'pdftoppm',
                    'pdftotext' => 'pdftotext',
                    'prlimit' => 'prlimit',
                    'php' => PHP_BINARY,
                ],
            ],
            'limits' => [
                'source_bytes' => 100_000_000,
                'physical_pages' => 100,
                'decoded_pixels_per_page' => 50_000_000,
                'parser_process_timeout' => 30,
                'ai_attempt_timeout' => 120,
                'invocation_deadline' => 600,
                'retained_output_bytes' => 10_000_000,
                'temporary_bytes' => 512_000_000,
                'ai_attempts' => 128,
                'inline_attachment_bytes' => 20_000_000,
            ],
        ]);
});

it('uses the native Illuminate Image API with the Imagick driver', function (): void {
    recordTiaExecution('image');

    $image = NativeImage::fromBytes(
        (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
    );

    expect(config('images.default'))->toBe('imagick')
        ->and($image->width())->toBe(1)
        ->and($image->height())->toBe(1);
});

it('blocks stray Laravel HTTP requests in source tests', function (): void {
    recordTiaExecution('network');

    expect(fn () => Http::get('https://provider.invalid/v1/models'))
        ->toThrow(RuntimeException::class);
});
