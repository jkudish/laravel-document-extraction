<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function removeProofSafetyDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        if ($item->isDir() && ! $item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($directory);
}

it('leaves caller configuration untouched when the TIA proof rejects a dirty tree', function (): void {
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/lde-tia-safety-'.bin2hex(random_bytes(8));
    mkdir($temporary.'/scripts', 0700, true);
    mkdir($temporary.'/config', 0700, true);
    copy($root.'/scripts/prove-tia', $temporary.'/scripts/prove-tia');
    copy($root.'/config/extraction.php', $temporary.'/config/extraction.php');
    $configuration = $temporary.'/config/extraction.php';
    $original = file_get_contents($configuration);

    expect($original)->toBeString();
    $callerContents = $original."\n// caller-owned dirty change\n";

    try {
        (new Process(['git', 'init', '--quiet'], $temporary))->mustRun();
        (new Process(['git', 'config', 'user.email', 'proof@example.invalid'], $temporary))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Proof'], $temporary))->mustRun();
        (new Process(['git', 'add', '.'], $temporary))->mustRun();
        (new Process(['git', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'fixture'], $temporary))->mustRun();
        file_put_contents($configuration, $callerContents);
        $process = new Process(['bash', $temporary.'/scripts/prove-tia'], $temporary);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('clean committed worktree')
            ->and(file_get_contents($configuration))->toBe($callerContents);
    } finally {
        removeProofSafetyDirectory($temporary);
    }
})->group('pr-workflow');

it('rejects detached HEAD before a TIA proof sandbox is created', function (): void {
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/lde-tia-detached-'.bin2hex(random_bytes(8));
    mkdir($temporary.'/scripts', 0700, true);
    copy($root.'/scripts/prove-tia', $temporary.'/scripts/prove-tia');

    try {
        (new Process(['git', 'init', '--quiet'], $temporary))->mustRun();
        (new Process(['git', 'config', 'user.email', 'proof@example.invalid'], $temporary))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Proof'], $temporary))->mustRun();
        (new Process(['git', 'add', '.'], $temporary))->mustRun();
        (new Process(['git', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'fixture'], $temporary))->mustRun();
        (new Process(['git', 'checkout', '--quiet', '--detach', 'HEAD'], $temporary))->mustRun();
        $process = new Process(['bash', $temporary.'/scripts/prove-tia'], $temporary);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('named source branch, not detached HEAD')
            ->and(is_dir($temporary.'/.pest'))->toBeFalse();
    } finally {
        removeProofSafetyDirectory($temporary);
    }
})->group('pr-workflow');

it('keeps PAO failure fixtures outside discovery and cannot delete a caller-owned test file', function (): void {
    $root = dirname(__DIR__, 2);
    $callerContents = "<?php\n\ndeclare(strict_types=1);\n\nit('is caller owned', fn () => expect(true)->toBeTrue());\n";
    $temporary = sys_get_temp_dir().'/lde-pao-safety-'.bin2hex(random_bytes(8));

    if (! mkdir($temporary.'/scripts', 0700, true) || ! mkdir($temporary.'/tests', 0700, true)) {
        throw new RuntimeException('Unable to create PAO safety test directory.');
    }

    copy($root.'/scripts/prove-pao', $temporary.'/scripts/prove-pao');
    $callerFile = $temporary.'/tests/PaoFailureProof.php';
    $fakePhp = $temporary.'/php';
    $callLog = $temporary.'/calls.log';
    file_put_contents($fakePhp, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"$FAKE_CALL_LOG"
if [[ -n "${PAO_DISABLE:-}" ]]; then
    printf 'PAO_DISABLE reached the explicit PAO proof.\n' >&2
    exit 24
fi
if [[ "$*" == *"/.pest/proofs/PaoFailureProof-"* ]]; then
    printf '{"result":"failed"}\n'
    exit 23
fi
printf '{"result":"passed"}\n'
BASH);
    chmod($fakePhp, 0700);

    try {
        file_put_contents($callerFile, $callerContents);
        $process = new Process(
            ['bash', $temporary.'/scripts/prove-pao'],
            $temporary,
            ['PHP_BINARY' => $fakePhp, 'FAKE_CALL_LOG' => $callLog, 'PAO_DISABLE' => '1'],
        );
        $process->run();
        $calls = (string) file_get_contents($callLog);

        expect($process->isSuccessful())->toBeTrue()
            ->and(file_get_contents($callerFile))->toBe($callerContents)
            ->and($calls)->toContain('/.pest/proofs/PaoFailureProof-')
            ->and($calls)->not->toContain('/tests/PaoFailureProof.php');
    } finally {
        removeProofSafetyDirectory($temporary);
    }
})->group('pr-workflow');
