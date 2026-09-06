<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Preparation;

use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Jkudish\DocumentExtraction\Exceptions\PreparationException;
use Jkudish\DocumentExtraction\ExtractionInvocation;
use Jkudish\DocumentExtraction\Source\SourceSnapshot;
use Jkudish\DocumentExtraction\TerminalOperation;

/** @phpstan-import-type ExtractionConfiguration from \Jkudish\DocumentExtraction\PendingExtraction */
final readonly class DocumentPreparer
{
    public function __construct(
        private WorkerRunner $worker,
        private TextPreparer $text,
    ) {}

    /** @param ExtractionConfiguration $configuration */
    public function prepare(
        ExtractionInvocation $invocation,
        SourceSnapshot $snapshot,
        PreparationWorkspace $workspace,
        array $configuration,
    ): PreparedDocument {
        return match (true) {
            $snapshot->mediaType === 'application/pdf' => $this->pdf($invocation, $snapshot, $workspace, $configuration),
            str_starts_with($snapshot->mediaType, 'image/') => $this->image($invocation, $snapshot, $workspace, $configuration),
            default => $this->directText($snapshot, $configuration),
        };
    }

    /** @param ExtractionConfiguration $configuration */
    private function directText(SourceSnapshot $snapshot, array $configuration): PreparedDocument
    {
        $contents = $this->text->prepare(
            $snapshot->path,
            $snapshot->mediaType,
            $configuration['limits']['retained_output_bytes'],
            $snapshot->deadline,
        );

        return new PreparedDocument(
            mediaType: $snapshot->mediaType,
            pageCount: null,
            pages: [],
            selectedPages: null,
            directText: $contents,
        );
    }

    /** @param ExtractionConfiguration $configuration */
    private function pdf(
        ExtractionInvocation $invocation,
        SourceSnapshot $snapshot,
        PreparationWorkspace $workspace,
        array $configuration,
    ): PreparedDocument {
        $info = $this->runWorker('pdf_info', [
            'source' => $snapshot->path,
            'source_bytes' => $snapshot->size,
            'page_limit' => $configuration['limits']['physical_pages'],
        ], $snapshot, $workspace, $configuration);
        $pageCount = $this->positiveInteger($info['page_count'] ?? null);
        $selected = $this->selectedPages($invocation->pages, $pageCount);
        $dimensions = $info['dimensions'] ?? null;

        if (! is_array($dimensions)) {
            throw PreparationException::make('invalid_pdf', 'The PDF document metadata is incomplete.');
        }

        $inventory = $this->runWorker('pdf_inventory', [
            'source' => $snapshot->path,
            'page_count' => $pageCount,
        ], $snapshot, $workspace, $configuration);
        $rasterPages = $this->positiveIntegerList($inventory['raster_pages'] ?? null, $pageCount);
        $rasterLookup = array_fill_keys($rasterPages, true);
        $retainedBytes = 0;
        $pages = [];
        $hasUsableText = false;

        foreach ($selected as $page) {
            $remainingOutput = max(1, $configuration['limits']['retained_output_bytes'] - $retainedBytes);
            $textResult = $this->runWorker('pdf_text', [
                'source' => $snapshot->path,
                'page' => $page,
                'output_limit' => $remainingOutput,
            ], $snapshot, $workspace, $configuration, $this->protocolLimit($remainingOutput));
            $encoded = $textResult['text_base64'] ?? null;
            $text = null;

            if (is_string($encoded)) {
                $decoded = base64_decode($encoded, true);

                if (! is_string($decoded)) {
                    throw PreparationException::make('preparation_failed', 'The isolated document worker returned invalid text.');
                }

                $text = $decoded;
                $retainedBytes += strlen($text);
            } elseif (($textResult['text_error'] ?? null) !== true) {
                throw PreparationException::make('preparation_failed', 'The isolated document worker returned invalid text.');
            }

            if ($retainedBytes > $configuration['limits']['retained_output_bytes']) {
                throw PreparationException::make('output_limit_exceeded', 'Document preparation exceeded the configured output byte limit.');
            }

            $usableText = $text !== null && trim($text) !== '';
            $needsOcr = ! $usableText || isset($rasterLookup[$page]);
            $hasUsableText = $hasUsableText || $usableText;
            $visualPath = null;
            $visualBytes = null;

            if ($invocation->operation === TerminalOperation::Extract || ($needsOcr && ! $invocation->withoutAi)) {
                $pageDimensions = $dimensions[$page] ?? $dimensions[(string) $page] ?? null;

                if (! is_array($pageDimensions)) {
                    throw PreparationException::make('invalid_pdf', 'The PDF document metadata is incomplete.');
                }

                [$width, $height] = $this->renderDimensions(
                    $pageDimensions['width_points'] ?? null,
                    $pageDimensions['height_points'] ?? null,
                    $configuration['preparation']['render_dpi'],
                    $configuration['limits']['decoded_pixels_per_page'],
                );
                $visualPath = $workspace->outputPath(sprintf('page-%03d.png', $page));
                $render = $this->runWorker('pdf_render', [
                    'source' => $snapshot->path,
                    'output' => $visualPath,
                    'page' => $page,
                    'dpi' => $configuration['preparation']['render_dpi'],
                    'expected_width' => $width,
                    'expected_height' => $height,
                    'temporary_limit' => $workspace->remainingBytes(),
                ], $snapshot, $workspace, $configuration);
                $visualBytes = $this->positiveInteger($render['bytes'] ?? null);
                $this->validateVisual($visualPath, $visualBytes);
                $workspace->enforceBudget();
            }

            $pages[] = new PreparedPage($page, $text, $visualPath, $needsOcr, $visualBytes);
        }

        $directText = $hasUsableText
            ? implode("\f", array_map(static fn (PreparedPage $page): string => $page->text ?? '', $pages))
            : null;

        return new PreparedDocument(
            mediaType: $snapshot->mediaType,
            pageCount: $pageCount,
            pages: $pages,
            selectedPages: $selected,
            directText: $directText,
        );
    }

    /** @param ExtractionConfiguration $configuration */
    private function image(
        ExtractionInvocation $invocation,
        SourceSnapshot $snapshot,
        PreparationWorkspace $workspace,
        array $configuration,
    ): PreparedDocument {
        $inspection = $this->runWorker('image_inspect', [
            'source' => $snapshot->path,
            'media_type' => $snapshot->mediaType,
            'page_limit' => $configuration['limits']['physical_pages'],
            'pixel_limit' => $configuration['limits']['decoded_pixels_per_page'],
        ], $snapshot, $workspace, $configuration);
        $pageCount = $this->positiveInteger($inspection['page_count'] ?? null);
        $selected = $this->selectedPages($invocation->pages, $pageCount);
        $dimensions = $inspection['dimensions'] ?? null;

        if (! is_array($dimensions) || count($dimensions) !== $pageCount) {
            throw PreparationException::make('unsupported_codec', 'The runtime could not inspect every image frame.');
        }

        $pages = [];

        foreach ($selected as $page) {
            $visualPath = null;
            $visualBytes = null;

            if (! $invocation->withoutAi) {
                $dimension = $dimensions[$page - 1] ?? null;

                if (! is_array($dimension)) {
                    throw PreparationException::make('unsupported_codec', 'The runtime could not inspect every image frame.');
                }

                $width = $this->positiveInteger($dimension['width'] ?? null);
                $height = $this->positiveInteger($dimension['height'] ?? null);
                $visualPath = $workspace->outputPath(sprintf('page-%03d.png', $page));
                $normalized = $this->runWorker('image_normalize', [
                    'source' => $snapshot->path,
                    'output' => $visualPath,
                    'media_type' => $snapshot->mediaType,
                    'frame' => $page - 1,
                    'expected_width' => $width,
                    'expected_height' => $height,
                    'temporary_limit' => $workspace->remainingBytes(),
                ], $snapshot, $workspace, $configuration);
                $visualBytes = $this->positiveInteger($normalized['bytes'] ?? null);
                $this->validateVisual($visualPath, $visualBytes);
                $workspace->enforceBudget();
            }

            $pages[] = new PreparedPage($page, null, $visualPath, true, $visualBytes);
        }

        return new PreparedDocument(
            mediaType: $snapshot->mediaType,
            pageCount: $pageCount,
            pages: $pages,
            selectedPages: $selected,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  ExtractionConfiguration  $configuration
     * @return array<string, mixed>
     */
    private function runWorker(
        string $mode,
        array $payload,
        SourceSnapshot $snapshot,
        PreparationWorkspace $workspace,
        array $configuration,
        int $protocolLimit = 1_000_000,
    ): array {
        return $this->worker->run(
            mode: $mode,
            payload: $payload,
            configuredBinaries: $configuration['preparation']['binaries'],
            deadline: $snapshot->deadline,
            operationTimeout: $configuration['limits']['parser_process_timeout'],
            nativeMemoryBytes: $configuration['preparation']['native_memory_bytes'],
            phpMemoryBytes: $configuration['preparation']['php_memory_bytes'],
            fileSizeLimit: $workspace->remainingBytes(),
            workspace: $workspace->path,
            protocolOutputLimit: $protocolLimit,
        );
    }

    /**
     * @param  list<int>  $requested
     * @return list<int>
     */
    private function selectedPages(array $requested, int $pageCount): array
    {
        if ($requested === []) {
            return range(1, $pageCount);
        }

        foreach ($requested as $page) {
            if ($page > $pageCount) {
                throw ConfigurationException::make('invalid_pages', 'A selected page exceeds the source page count.');
            }
        }

        return $requested;
    }

    /** @return list<int> */
    private function positiveIntegerList(mixed $values, int $maximum): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw PreparationException::make('preparation_failed', 'The isolated document worker returned invalid page metadata.');
        }

        $normalized = [];

        foreach ($values as $value) {
            $integer = $this->positiveInteger($value);

            if ($integer > $maximum || isset($normalized[$integer])) {
                throw PreparationException::make('preparation_failed', 'The isolated document worker returned invalid page metadata.');
            }

            $normalized[$integer] = true;
        }

        $result = array_keys($normalized);
        sort($result, SORT_NUMERIC);

        return $result;
    }

    private function positiveInteger(mixed $value): int
    {
        if (! is_int($value) || $value < 1) {
            throw PreparationException::make('preparation_failed', 'The isolated document worker returned an invalid numeric value.');
        }

        return $value;
    }

    /** @return array{int, int} */
    private function renderDimensions(mixed $widthPoints, mixed $heightPoints, int $dpi, int $pixelLimit): array
    {
        if (! is_float($widthPoints) && ! is_int($widthPoints)
            || ! is_float($heightPoints) && ! is_int($heightPoints)) {
            throw PreparationException::make('invalid_pdf', 'The PDF document contains invalid page dimensions.');
        }

        $width = (int) round(((float) $widthPoints / 72) * $dpi);
        $height = (int) round(((float) $heightPoints / 72) * $dpi);

        if ($width < 1 || $height < 1 || $width > intdiv($pixelLimit, $height)) {
            throw PreparationException::make('pixel_limit_exceeded', 'A document page exceeds the configured decoded pixel limit.');
        }

        return [$width, $height];
    }

    private function validateVisual(string $path, int $reportedBytes): void
    {
        $stat = @stat($path);

        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $reportedBytes || $reportedBytes < 1) {
            throw PreparationException::make('preparation_failed', 'A normalized visual page is missing or invalid.');
        }
    }

    private function protocolLimit(int $outputLimit): int
    {
        return (int) ceil($outputLimit * 4 / 3) + 1_000_000;
    }
}
