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

it('keeps PAO proof files privately owned without changing caller paths', function (bool $proofFails, bool $proofsDirectoryHasFile): void {
    $root = dirname(__DIR__, 2);
    $callerContents = "<?php\n\ndeclare(strict_types=1);\n\nit('is caller owned', fn () => expect(true)->toBeTrue());\n";
    $temporary = sys_get_temp_dir().'/lde-pao-safety-'.bin2hex(random_bytes(8));

    if (! mkdir($temporary.'/scripts', 0700, true)
        || ! mkdir($temporary.'/tests', 0700, true)
        || ! mkdir($temporary.'/.pest/proofs', 0755, true)) {
        throw new RuntimeException('Unable to create PAO safety test directory.');
    }

    copy($root.'/scripts/prove-pao', $temporary.'/scripts/prove-pao');
    $callerFile = $temporary.'/tests/PaoFailureProof.php';
    $callerProof = $temporary.'/.pest/proofs/caller-owned.txt';
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
if [[ -n "${FAKE_PAO_PROOF_FAILURE:-}" ]]; then
    printf '{"result":"failed"}\n'
    exit 25
fi
if [[ "$*" == *"/.pest/"* && "$*" == *"PaoFailureProof"* ]]; then
    printf '{"result":"failed"}\n'
    exit 23
fi
printf '{"result":"passed"}\n'
BASH);
    chmod($fakePhp, 0700);

    try {
        file_put_contents($callerFile, $callerContents);
        if ($proofsDirectoryHasFile) {
            file_put_contents($callerProof, 'caller-owned proof contents');
        }
        chmod($temporary.'/.pest', 0755);
        chmod($temporary.'/.pest/proofs', 0755);

        $process = new Process(
            ['bash', $temporary.'/scripts/prove-pao'],
            $temporary,
            [
                'PHP_BINARY' => $fakePhp,
                'FAKE_CALL_LOG' => $callLog,
                'FAKE_PAO_PROOF_FAILURE' => $proofFails ? '1' : '',
                'PAO_DISABLE' => '1',
            ],
        );
        $process->run();
        $calls = (string) file_get_contents($callLog);
        $proofsDirectory = $temporary.'/.pest/proofs';
        $proofsDirectoryExists = is_dir($proofsDirectory);
        $proofsDirectoryMode = $proofsDirectoryExists ? fileperms($proofsDirectory) & 0777 : null;
        $proofsDirectoryEntries = $proofsDirectoryExists
            ? array_values(array_diff(scandir($proofsDirectory) ?: [], ['.', '..']))
            : null;
        $proofDirectories = glob($temporary.'/.pest/PaoProof-*', GLOB_ONLYDIR) ?: [];

        expect($process->isSuccessful())->toBe(! $proofFails)
            ->and(file_get_contents($callerFile))->toBe($callerContents)
            ->and($proofsDirectoryExists)->toBeTrue()
            ->and($proofsDirectoryMode)->toBe(0755)
            ->and($proofsDirectoryEntries)->toBe($proofsDirectoryHasFile ? ['caller-owned.txt'] : [])
            ->and($proofsDirectoryHasFile ? file_get_contents($callerProof) : null)
            ->toBe($proofsDirectoryHasFile ? 'caller-owned proof contents' : null)
            ->and(fileperms($temporary.'/.pest') & 0777)->toBe(0755)
            ->and($proofDirectories)->toBe([])
            ->and($calls)->not->toContain('/tests/PaoFailureProof.php');

        if (! $proofFails) {
            expect($calls)->toContain('/.pest/PaoProof-')
                ->and($calls)->toContain('/PaoFailureProof.php');
        }
    } finally {
        removeProofSafetyDirectory($temporary);
    }
})->with([
    'success with an empty caller proofs directory' => [false, false],
    'success with a nonempty caller proofs directory' => [false, true],
    'failure with an empty caller proofs directory' => [true, false],
    'failure with a nonempty caller proofs directory' => [true, true],
])->group('pr-workflow');
