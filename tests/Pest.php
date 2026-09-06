<?php

declare(strict_types=1);

use Jkudish\DocumentExtraction\Tests\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

pest()->extend(TestCase::class)->in('Feature');

pest()->tia()
    ->defaultBranch('main')
    ->directory('.pest/tia/'.nativeRuntimeFingerprint())
    ->watch([
        'composer.json' => 'tests',
        'config/*.php' => 'tests/Feature/ServiceProviderTest.php',
        'resources/prompts/*' => 'tests/Unit/PromptTest.php',
        'resources/schemas/*' => 'tests/Unit/SchemaFileTest.php',
        'resources/workers/*' => 'tests/Feature/PreparationTest.php',
        'tests/Fixtures/Images/*' => 'tests/Feature/PreparationTest.php',
        'tests/Fixtures/Pdf/*' => 'tests/Feature/PreparationTest.php',
        'tests/Fixtures/*' => 'tests/Unit/FixtureTest.php',
    ]);

function recordTiaExecution(string $test): void
{
    $log = getenv('TIA_PROOF_LOG');

    if (! is_string($log) || $log === '') {
        return;
    }

    file_put_contents($log, $test.PHP_EOL, FILE_APPEND | LOCK_EX);
}

function nativeRuntimeFingerprint(): string
{
    $imagick = class_exists(Imagick::class)
        ? json_encode([
            'extension' => phpversion('imagick'),
            'version' => Imagick::getVersion(),
            'formats' => Imagick::queryFormats(),
        ], JSON_THROW_ON_ERROR)
        : 'missing';

    $fingerprint = json_encode([
        'php' => PHP_VERSION,
        'imagick' => $imagick,
        'pdfimages' => nativeCommandVersion('pdfimages'),
        'pdfinfo' => nativeCommandVersion('pdfinfo'),
        'pdftoppm' => nativeCommandVersion('pdftoppm'),
        'pdftotext' => nativeCommandVersion('pdftotext'),
        'prlimit' => nativeCommandVersion('prlimit'),
        'runtime_salt' => getenv('LDE_TIA_RUNTIME_SALT') ?: null,
    ], JSON_THROW_ON_ERROR);

    return hash('sha256', $fingerprint);
}

function nativeCommandVersion(string $command): string
{
    $binary = (new ExecutableFinder)->find($command);

    if ($binary === null) {
        return 'missing';
    }

    $process = new Process([$binary, '-v']);
    $process->run();

    return trim($process->getOutput().$process->getErrorOutput());
}
