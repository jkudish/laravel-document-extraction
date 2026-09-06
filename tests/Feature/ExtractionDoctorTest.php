<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('reports actual offline preparation readiness without provider or network calls', function (): void {
    $exit = Artisan::call('extraction:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Document preparation is ready')
        ->and($output)->toContain('pdfinfo is executable')
        ->and($output)->toContain('heic-heif decoding')
        ->and($output)->toContain('No provider or network checks were made');
});

it('fails clearly when a required runtime capability is missing', function (): void {
    config()->set('extraction.preparation.binaries.pdfinfo', '/definitely/missing/pdfinfo');

    $exit = Artisan::call('extraction:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('pdfinfo is missing or unusable')
        ->and($output)->toContain('Document preparation is not ready');
});

it('fails clearly when bounded preparation limits are incomplete', function (): void {
    config()->set('extraction.limits.decoded_pixels_per_page', 0);

    $exit = Artisan::call('extraction:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('configuration: Preparation limits are incomplete or invalid')
        ->and($output)->toContain('Document preparation is not ready');
});
