<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Jkudish\DocumentExtraction\Exceptions\ProcessingUnavailableException;
use Jkudish\DocumentExtraction\Preparation\Deadline;
use Jkudish\DocumentExtraction\Preparation\DocumentPreparer;
use Jkudish\DocumentExtraction\Preparation\PreparationWorkspace;
use Jkudish\DocumentExtraction\Preparation\PreparedDocument;
use Jkudish\DocumentExtraction\Preparation\PreparedPage;
use Jkudish\DocumentExtraction\Results\CostSummary;
use Jkudish\DocumentExtraction\Results\DocumentResult;
use Jkudish\DocumentExtraction\Results\ExtractionError;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use Jkudish\DocumentExtraction\Results\PageResult;
use Jkudish\DocumentExtraction\Source\SourceInput;
use Jkudish\DocumentExtraction\Source\SourceSnapshot;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Throwable;

/** @phpstan-import-type ExtractionConfiguration from PendingExtraction */
class DocumentExtraction
{
    public function __construct(
        private readonly Repository $config,
        protected readonly FilesystemFactory $filesystems,
        protected readonly Container $container,
    ) {}

    public function fromPath(string $path): PendingExtraction
    {
        return $this->pending(SourceInput::path($path));
    }

    public function fromStorage(string $path, ?string $disk = null): PendingExtraction
    {
        return $this->pending(SourceInput::storage($path, $disk));
    }

    public function fromUpload(UploadedFile $file): PendingExtraction
    {
        return $this->pending(SourceInput::upload($file));
    }

    public function fromStream(mixed $stream, ?string $mimeType = null): PendingExtraction
    {
        return $this->pending(SourceInput::stream($stream, $mimeType));
    }

    public function fromString(string $contents, ?string $mimeType = null): PendingExtraction
    {
        return $this->pending(SourceInput::contents($contents, $mimeType));
    }

    public function execute(ExtractionInvocation $invocation): ExtractionResult
    {
        $this->validateInvocation($invocation);

        $limits = $invocation->configuration['limits'];
        $deadline = Deadline::afterSeconds($limits['invocation_deadline']);
        $snapshot = SourceSnapshot::capture(
            $invocation->source,
            $this->filesystems,
            $limits['source_bytes'],
            $limits['temporary_bytes'],
            $deadline,
        );

        try {
            if ($invocation->pages !== [] && ! $snapshot->isPaginated()) {
                throw ConfigurationException::make(
                    'invalid_pages',
                    'Page selection is unavailable for unpaginated source content.',
                );
            }

            return $this->process($invocation, $snapshot);
        } finally {
            $snapshot->cleanup();
        }
    }

    protected function process(ExtractionInvocation $invocation, SourceSnapshot $snapshot): ExtractionResult
    {
        $workspace = PreparationWorkspace::create(
            $snapshot->path,
            $snapshot->size,
            $invocation->configuration['limits']['temporary_bytes'],
        );

        try {
            $prepared = $this->container->make(DocumentPreparer::class)->prepare(
                $invocation,
                $snapshot,
                $workspace,
                $invocation->configuration,
            );

            return $this->processPrepared($invocation, $snapshot, $prepared);
        } finally {
            $workspace->cleanup();
        }
    }

    protected function processPrepared(
        ExtractionInvocation $invocation,
        SourceSnapshot $snapshot,
        PreparedDocument $prepared,
    ): ExtractionResult {
        if ($invocation->operation === TerminalOperation::Extract) {
            throw ProcessingUnavailableException::make(
                'ai_processing_unavailable',
                'Structured AI extraction is not implemented in this package phase.',
            );
        }

        if ($prepared->pageCount === null) {
            return new ExtractionResult(
                documents: [new DocumentResult(text: $prepared->directText ?? '')],
                sourceSha256: $snapshot->sha256,
                mediaType: $prepared->mediaType,
                cost: CostSummary::unavailable(),
            );
        }

        if ($prepared->requiresAi() && ! $invocation->withoutAi) {
            throw ProcessingUnavailableException::make(
                'ai_processing_unavailable',
                'OCR execution is not implemented in this package phase; visual pages were prepared safely.',
            );
        }

        $selectedPages = $prepared->selectedPages ?? [];

        if (! $prepared->requiresAi()) {
            return new ExtractionResult(
                documents: [new DocumentResult(pages: $selectedPages, text: $prepared->directText ?? '')],
                sourceSha256: $snapshot->sha256,
                mediaType: $prepared->mediaType,
                pageCount: $prepared->pageCount,
                pages: array_map(
                    static fn (PreparedPage $page): PageResult => new PageResult($page->page, $page->text ?? ''),
                    $prepared->pages,
                ),
                cost: CostSummary::unavailable(),
            );
        }

        $unprocessed = [];

        foreach ($prepared->pages as $page) {
            if ($page->needsOcr) {
                $unprocessed[] = $page->page;
            }
        }

        $error = new ExtractionError(
            code: 'ocr_required',
            message: 'One or more visual pages require OCR and were left unprocessed because AI is disabled.',
            pages: $unprocessed,
            retryable: false,
        );

        return new ExtractionResult(
            documents: [new DocumentResult(
                pages: $selectedPages,
                text: $prepared->directText,
                complete: false,
                error: $error,
            )],
            sourceSha256: $snapshot->sha256,
            mediaType: $prepared->mediaType,
            pageCount: $prepared->pageCount,
            pages: array_map(
                static fn (PreparedPage $page): PageResult => $page->needsOcr
                    ? new PageResult($page->page, $page->text, complete: false, error: $error)
                    : new PageResult($page->page, $page->text ?? ''),
                $prepared->pages,
            ),
            errors: [$error],
            cost: CostSummary::unavailable(),
            coverageComplete: false,
        );
    }

    protected function validateInvocation(ExtractionInvocation $invocation): void
    {
        if ($invocation->agent === null) {
            return;
        }

        $agent = $this->resolveAgent($invocation->agent);

        if ($agent instanceof HasTools) {
            $this->rejectNonEmptyCapability(
                static fn (): iterable => $agent->tools(),
                'Extraction agents with tools are not supported.',
            );
        }

        if ($agent instanceof Conversational) {
            $this->rejectNonEmptyCapability(
                static fn (): iterable => $agent->messages(),
                'Extraction agents with conversation history are not supported.',
            );
        }
    }

    private function pending(SourceInput $source): PendingExtraction
    {
        return new PendingExtraction($this, $source, $this->configurationSnapshot());
    }

    /** @return ExtractionConfiguration */
    private function configurationSnapshot(): array
    {
        $configuration = $this->config->get('extraction');

        if (! is_array($configuration)) {
            throw ConfigurationException::make(
                'invalid_configuration',
                'Extraction configuration must be an array.',
            );
        }

        $this->validateProvider($configuration['provider'] ?? null, 'provider');
        $this->validateOptionalString($configuration['model'] ?? null, 'model');
        $this->validatePositiveInteger($configuration['timeout'] ?? null, 'timeout');
        $configuration['provider'] = $configuration['provider'] ?? null;
        $configuration['model'] = $configuration['model'] ?? null;

        foreach (['options', 'middleware'] as $key) {
            if (! is_array($configuration[$key] ?? null)) {
                throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$key}] must be an array.");
            }
        }

        foreach (['ocr', 'detection'] as $purpose) {
            $settings = $configuration[$purpose] ?? null;

            if (! is_array($settings)) {
                throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$purpose}] must be an array.");
            }

            $this->validateProvider($settings['provider'] ?? null, "{$purpose}.provider");
            $this->validateOptionalString($settings['model'] ?? null, "{$purpose}.model");
            $settings['provider'] = $settings['provider'] ?? null;
            $settings['model'] = $settings['model'] ?? null;

            if (! is_array($settings['options'] ?? null)) {
                throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$purpose}.options] must be an array.");
            }

            if (is_array($settings['provider'] ?? null) && ($settings['model'] ?? null) !== null) {
                throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$purpose}] cannot combine a provider list with a separate model.");
            }

            $configuration[$purpose] = $settings;
        }

        $preparation = $configuration['preparation'] ?? null;

        if (! is_array($preparation)) {
            throw ConfigurationException::make('invalid_configuration', 'Extraction configuration [preparation] must be an array.');
        }

        foreach (['render_dpi', 'native_memory_bytes', 'php_memory_bytes'] as $setting) {
            $this->validatePositiveInteger($preparation[$setting] ?? null, "preparation.{$setting}");
        }

        $binaries = $preparation['binaries'] ?? null;

        if (! is_array($binaries)) {
            throw ConfigurationException::make('invalid_configuration', 'Extraction configuration [preparation.binaries] must be an array.');
        }

        foreach (['pdfinfo', 'pdfimages', 'pdftoppm', 'pdftotext', 'prlimit', 'php'] as $binary) {
            if (! is_string($binaries[$binary] ?? null) || trim($binaries[$binary]) === '') {
                throw ConfigurationException::make('invalid_configuration', "Extraction configuration [preparation.binaries.{$binary}] must not be empty.");
            }
        }

        /** @var array{pdfinfo: string, pdfimages: string, pdftoppm: string, pdftotext: string, prlimit: string, php: string} $binaries */
        $preparation['binaries'] = $binaries;
        /** @var array{render_dpi: int, native_memory_bytes: int, php_memory_bytes: int, binaries: array{pdfinfo: string, pdfimages: string, pdftoppm: string, pdftotext: string, prlimit: string, php: string}} $preparation */
        $configuration['preparation'] = $preparation;

        if (is_array($configuration['provider'] ?? null) && ($configuration['model'] ?? null) !== null) {
            throw ConfigurationException::make('invalid_configuration', 'Extraction configuration cannot combine a provider list with a separate model.');
        }

        $limits = $configuration['limits'] ?? null;

        if (! is_array($limits)) {
            throw ConfigurationException::make('invalid_configuration', 'Extraction configuration [limits] must be an array.');
        }

        foreach ([
            'source_bytes',
            'physical_pages',
            'decoded_pixels_per_page',
            'parser_process_timeout',
            'ai_attempt_timeout',
            'invocation_deadline',
            'retained_output_bytes',
            'temporary_bytes',
            'ai_attempts',
            'inline_attachment_bytes',
        ] as $limit) {
            $this->validatePositiveInteger($limits[$limit] ?? null, "limits.{$limit}");
        }

        /** @var ExtractionConfiguration $configuration */
        return $configuration;
    }

    private function resolveAgent(Agent|string $agent): Agent&HasStructuredOutput
    {
        if (is_string($agent)
            && (! is_a($agent, Agent::class, true) || ! is_a($agent, HasStructuredOutput::class, true))) {
            throw ConfigurationException::make(
                'invalid_agent',
                'The configured extraction agent must implement Agent and HasStructuredOutput.',
            );
        }

        if ($agent instanceof Agent && ! $agent instanceof HasStructuredOutput) {
            throw ConfigurationException::make(
                'invalid_agent',
                'The configured extraction agent must implement HasStructuredOutput.',
            );
        }

        try {
            $resolved = is_string($agent) ? $this->container->make($agent) : $agent;
        } catch (Throwable $exception) {
            throw ConfigurationException::make(
                'invalid_agent',
                'The configured extraction agent could not be resolved.',
                $exception,
            );
        }

        if (! $resolved instanceof Agent || ! $resolved instanceof HasStructuredOutput) {
            throw ConfigurationException::make(
                'invalid_agent',
                'The configured extraction agent must implement Agent and HasStructuredOutput.',
            );
        }

        return $resolved;
    }

    private function validateProvider(mixed $provider, string $key): void
    {
        if ($provider === null || $provider instanceof Lab) {
            return;
        }

        if (is_string($provider)) {
            if (trim($provider) !== '') {
                return;
            }

            throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$key}] must not be empty.");
        }

        if (! is_array($provider) || $provider === []) {
            throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$key}] must be a provider name, Lab, or non-empty provider list.");
        }

        $isList = array_is_list($provider);

        $seen = [];

        foreach ($provider as $alias => $configuredModel) {
            if ($isList) {
                $name = $configuredModel instanceof Lab ? $configuredModel->value : $configuredModel;

                if (! is_string($name) || trim($name) === '') {
                    throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$key}] contains an invalid provider.");
                }
            } else {
                if (! is_string($alias) || trim($alias) === '' || ! is_string($configuredModel) || trim($configuredModel) === '') {
                    throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$key}] contains an invalid provider/model pair.");
                }

                $name = $alias;
            }

            $normalized = strtolower(trim($name));

            if (isset($seen[$normalized])) {
                throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$key}] contains a duplicate provider.");
            }

            $seen[$normalized] = true;
        }
    }

    private function validateOptionalString(mixed $value, string $key): void
    {
        if ($value !== null && (! is_string($value) || trim($value) === '')) {
            throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$key}] must be a non-empty string or null.");
        }
    }

    private function validatePositiveInteger(mixed $value, string $key): void
    {
        if (! is_int($value) || $value < 1) {
            throw ConfigurationException::make('invalid_configuration', "Extraction configuration [{$key}] must be a positive integer.");
        }
    }

    /** @param callable(): iterable<mixed> $capability */
    private function rejectNonEmptyCapability(callable $capability, string $message): void
    {
        try {
            foreach ($capability() as $_value) {
                throw ConfigurationException::make('unsupported_agent_capability', $message);
            }
        } catch (ConfigurationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ConfigurationException::make(
                'invalid_agent',
                'The configured extraction agent capabilities could not be inspected safely.',
                $exception,
            );
        }
    }
}
