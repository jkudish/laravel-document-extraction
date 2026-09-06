<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\ExtractionInvocation;
use Jkudish\DocumentExtraction\Results\CostSummary;
use Jkudish\DocumentExtraction\Results\DocumentResult;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use Jkudish\DocumentExtraction\Source\SourceSnapshot;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use RuntimeException;

final class RecordingDocumentExtraction extends DocumentExtraction
{
    /** @var list<array{contents: string, path: string, sha256: string, size: int, mediaType: string, fileMode: int, directoryMode: int}> */
    public array $snapshots = [];

    /** @var list<ExtractionInvocation> */
    public array $invocations = [];

    public bool $failDuringProcessing = false;

    public function __construct(Repository $config, FilesystemFactory $filesystems, Container $container)
    {
        parent::__construct($config, $filesystems, $container);
    }

    protected function process(
        ExtractionInvocation $invocation,
        SourceSnapshot $snapshot,
        (Agent&HasStructuredOutput)|null $agent,
    ): ExtractionResult {
        $this->invocations[] = $invocation;
        $contents = file_get_contents($snapshot->path);
        $fileMode = fileperms($snapshot->path);
        $directoryMode = fileperms(dirname($snapshot->path));

        if (! is_string($contents) || $fileMode === false || $directoryMode === false) {
            throw new RuntimeException('The test could not read the private source snapshot.');
        }

        $this->snapshots[] = [
            'contents' => $contents,
            'path' => $snapshot->path,
            'sha256' => $snapshot->sha256,
            'size' => $snapshot->size,
            'mediaType' => $snapshot->mediaType,
            'fileMode' => $fileMode & 0777,
            'directoryMode' => $directoryMode & 0777,
        ];

        if ($this->failDuringProcessing) {
            throw new RuntimeException('Simulated downstream failure.');
        }

        return new ExtractionResult(
            documents: [new DocumentResult(text: $contents)],
            sourceSha256: $snapshot->sha256,
            mediaType: $snapshot->mediaType,
            pageCount: $snapshot->pageCount,
            cost: CostSummary::unavailable(),
        );
    }
}
