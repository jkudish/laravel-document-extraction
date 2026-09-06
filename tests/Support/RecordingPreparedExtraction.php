<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\ExtractionInvocation;
use Jkudish\DocumentExtraction\Preparation\PreparedDocument;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use Jkudish\DocumentExtraction\Source\SourceSnapshot;

final class RecordingPreparedExtraction extends DocumentExtraction
{
    /** @var list<array{prepared: PreparedDocument, sourceSha256: string, visuals: list<array{path: string, mime: string, width: int, height: int, bytes: int}>}> */
    public array $preparations = [];

    public function __construct(Repository $config, FilesystemFactory $filesystems, Container $container)
    {
        parent::__construct($config, $filesystems, $container);
    }

    protected function processPrepared(
        ExtractionInvocation $invocation,
        SourceSnapshot $snapshot,
        PreparedDocument $prepared,
    ): ExtractionResult {
        $visuals = [];

        foreach ($prepared->pages as $page) {
            if ($page->visualPath === null) {
                continue;
            }

            $size = getimagesize($page->visualPath);
            $bytes = filesize($page->visualPath);

            if (! is_array($size) || ! is_int($bytes)) {
                throw new \RuntimeException('The prepared visual could not be inspected by the test.');
            }

            $visuals[] = [
                'path' => $page->visualPath,
                'mime' => $size['mime'],
                'width' => $size[0],
                'height' => $size[1],
                'bytes' => $bytes,
            ];
        }

        $this->preparations[] = [
            'prepared' => $prepared,
            'sourceSha256' => $snapshot->sha256,
            'visuals' => $visuals,
        ];

        return parent::processPrepared($invocation, $snapshot, $prepared);
    }
}
