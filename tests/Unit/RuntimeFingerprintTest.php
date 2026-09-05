<?php

declare(strict_types=1);

it('partitions TIA state by PHP and native document runtime', function (): void {
    recordTiaExecution('runtime');

    expect(nativeRuntimeFingerprint())->toMatch('/^[a-f0-9]{64}$/')
        ->and(nativeCommandVersion('pdfimages'))->not->toBe('missing')
        ->and(nativeCommandVersion('pdfinfo'))->not->toBe('missing')
        ->and(nativeCommandVersion('pdftoppm'))->not->toBe('missing')
        ->and(nativeCommandVersion('pdftotext'))->not->toBe('missing')
        ->and(class_exists(Imagick::class))->toBeTrue();
});
