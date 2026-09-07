<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use Jkudish\DocumentExtraction\Source\SourceInput;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;

/**
 * @phpstan-type ProviderRoute Lab|string|array<int, Lab|string>|array<string, string>|null
 * @phpstan-type ProviderOptions array<string, array<string, mixed>>
 * @phpstan-type PurposeConfiguration array{provider: ProviderRoute, model: ?string, timeout: ?int, options: ProviderOptions}
 * @phpstan-type LimitConfiguration array{source_bytes: int, physical_pages: int, decoded_pixels_per_page: int, parser_process_timeout: int, ai_attempt_timeout: int, invocation_deadline: int, retained_output_bytes: int, temporary_bytes: int, ai_attempts: int, inline_attachment_bytes: int}
 * @phpstan-type PreparationConfiguration array{render_dpi: int, native_memory_bytes: int, php_memory_bytes: int, binaries: array{pdfinfo: string, pdfimages: string, pdftoppm: string, pdftotext: string, prlimit: string, php: string}}
 * @phpstan-type ExtractionConfiguration array{provider: ProviderRoute, model: ?string, timeout: ?int, options: ProviderOptions, middleware: array<mixed>, ocr: PurposeConfiguration, detection: PurposeConfiguration, preparation: PreparationConfiguration, limits: LimitConfiguration}
 */
final class PendingExtraction
{
    private ?Closure $schema = null;

    private Agent|string|null $agent = null;

    private ?string $instructions = null;

    private bool $detectionEnabled = false;

    /** @var list<int> */
    private array $pages = [];

    private bool $aiDisabled = false;

    /** @param ExtractionConfiguration $configuration */
    public function __construct(
        private readonly DocumentExtraction $extraction,
        private readonly SourceInput $source,
        private readonly array $configuration,
    ) {}

    /** @param Closure(JsonSchema): array<string, Type> $schema */
    public function schema(Closure $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    public function using(Agent|string $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function instructions(string $instructions): static
    {
        if (trim($instructions) === '') {
            throw ConfigurationException::make('invalid_instructions', 'Extraction instructions must not be empty.');
        }

        $this->instructions = $instructions;

        return $this;
    }

    public function detectDocuments(bool $enabled = true): static
    {
        $this->detectionEnabled = $enabled;

        return $this;
    }

    /** @param array<mixed> $pages */
    public function pages(array $pages): static
    {
        if ($pages === [] || ! array_is_list($pages)) {
            throw ConfigurationException::make('invalid_pages', 'Selected pages must be a non-empty list of positive integers.');
        }

        $normalized = [];

        foreach ($pages as $page) {
            if (! is_int($page) || $page < 1 || isset($normalized[$page])) {
                throw ConfigurationException::make('invalid_pages', 'Selected pages must be unique positive integers.');
            }

            $normalized[$page] = true;
        }

        $this->pages = array_keys($normalized);
        sort($this->pages, SORT_NUMERIC);

        return $this;
    }

    public function withoutAi(): static
    {
        $this->aiDisabled = true;

        return $this;
    }

    /** @param array<mixed>|Lab|string|null $provider */
    public function text(
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): ExtractionResult {
        return $this->terminal(TerminalOperation::Text, $provider, $model, $timeout);
    }

    /** @param array<mixed>|Lab|string|null $provider */
    public function extract(
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): ExtractionResult {
        return $this->terminal(TerminalOperation::Extract, $provider, $model, $timeout);
    }

    /** @return ExtractionConfiguration */
    public function configuration(): array
    {
        return $this->configuration;
    }

    /** @return list<int> */
    public function selectedPages(): array
    {
        return $this->pages;
    }

    /** @param array<mixed>|Lab|string|null $provider */
    private function terminal(
        TerminalOperation $operation,
        Lab|array|string|null $provider,
        ?string $model,
        ?int $timeout,
    ): ExtractionResult {
        $this->validateModes($operation, $provider, $model, $timeout);
        [$resolvedProvider, $resolvedModel, $resolvedTimeout] = $this->resolvedRoute($operation, $provider, $model, $timeout);
        [$detectionProvider, $detectionModel, $detectionTimeout] = $this->detectionEnabled
            ? $this->resolvedPurposeRoute('detection')
            : [null, null, null];

        return $this->extraction->execute(new ExtractionInvocation(
            source: $this->source,
            operation: $operation,
            schema: $this->schema,
            agent: $this->agent,
            instructions: $this->instructions,
            detectDocuments: $this->detectionEnabled,
            pages: $this->pages,
            withoutAi: $this->aiDisabled,
            provider: $resolvedProvider,
            model: $resolvedModel,
            timeout: $resolvedTimeout,
            detectionProvider: $detectionProvider,
            detectionModel: $detectionModel,
            detectionTimeout: $detectionTimeout,
            configuration: $this->configuration,
        ));
    }

    /** @param array<mixed>|Lab|string|null $provider */
    private function validateModes(
        TerminalOperation $operation,
        Lab|array|string|null $provider,
        ?string $model,
        ?int $timeout,
    ): void {
        if ($this->schema !== null && $this->agent !== null) {
            throw ConfigurationException::make('conflicting_configuration', 'Configure either an inline schema or a structured agent, not both.');
        }

        if ($this->instructions !== null && $this->agent !== null) {
            throw ConfigurationException::make('conflicting_configuration', 'Inline instructions cannot be combined with an application agent.');
        }

        if ($operation === TerminalOperation::Text && ($this->schema !== null || $this->agent !== null)) {
            throw ConfigurationException::make('conflicting_configuration', 'Structured schemas and agents cannot be used with text().');
        }

        if ($operation === TerminalOperation::Extract && $this->schema === null && $this->agent === null) {
            throw ConfigurationException::make('missing_schema', 'Structured extraction requires an inline schema or structured agent.');
        }

        if ($this->instructions !== null && $this->schema === null) {
            throw ConfigurationException::make('conflicting_configuration', 'Inline instructions require an inline schema.');
        }

        if ($this->aiDisabled && ($operation === TerminalOperation::Extract || $this->detectionEnabled)) {
            throw ConfigurationException::make('ai_disabled', 'withoutAi() cannot be combined with structured extraction or document detection.');
        }

        if ($this->aiDisabled && ($provider !== null || $model !== null)) {
            throw ConfigurationException::make('conflicting_configuration', 'Provider and model overrides cannot be used with withoutAi().');
        }

        $this->validateProvider($provider);

        if (is_array($provider) && $model !== null) {
            throw ConfigurationException::make('conflicting_configuration', 'A provider list cannot be combined with a separate model.');
        }

        if ($model !== null && trim($model) === '') {
            throw ConfigurationException::make('invalid_model', 'The model override must not be empty.');
        }

        if ($timeout !== null && $timeout < 1) {
            throw ConfigurationException::make('invalid_timeout', 'The timeout override must be a positive integer.');
        }
    }

    /**
     * @param  array<mixed>|Lab|string|null  $provider
     * @return array{ProviderRoute, ?string, ?int}
     */
    private function resolvedRoute(
        TerminalOperation $operation,
        Lab|array|string|null $provider,
        ?string $model,
        ?int $timeout,
    ): array {
        if ($this->aiDisabled) {
            return [null, null, $timeout ?? $this->configuration['timeout']];
        }

        /** @var ProviderRoute $provider */
        $purpose = $operation === TerminalOperation::Text ? $this->configuration['ocr'] : [];
        $resolvedProvider = $provider ?? ($purpose['provider'] ?? null) ?? $this->configuration['provider'];
        $configuredModel = $model ?? ($purpose['model'] ?? null) ?? $this->configuration['model'];

        if (is_array($resolvedProvider) && $configuredModel !== null) {
            throw ConfigurationException::make(
                'conflicting_configuration',
                'A resolved provider list cannot be combined with a separate model.',
            );
        }

        $resolvedModel = is_array($resolvedProvider) ? null : $configuredModel;
        $resolvedTimeout = $timeout ?? ($purpose['timeout'] ?? null) ?? $this->configuration['timeout'];

        return [$resolvedProvider, $resolvedModel, $resolvedTimeout];
    }

    /** @return array{ProviderRoute, ?string, ?int} */
    private function resolvedPurposeRoute(string $purpose): array
    {
        /** @var PurposeConfiguration $configuration */
        $configuration = $this->configuration[$purpose];
        /** @var ProviderRoute $provider */
        $provider = $configuration['provider'] ?? $this->configuration['provider'];
        $model = $configuration['model'] ?? $this->configuration['model'];

        if (is_array($provider) && $model !== null) {
            throw ConfigurationException::make(
                'conflicting_configuration',
                "The resolved {$purpose} provider list cannot be combined with a separate model.",
            );
        }

        return [
            $provider,
            is_array($provider) ? null : $model,
            $configuration['timeout'] ?? $this->configuration['timeout'],
        ];
    }

    /** @param array<mixed>|Lab|string|null $provider */
    private function validateProvider(Lab|array|string|null $provider): void
    {
        if ($provider === null || $provider instanceof Lab) {
            return;
        }

        if (is_string($provider)) {
            if (trim($provider) === '') {
                throw ConfigurationException::make('invalid_provider', 'The provider override must not be empty.');
            }

            return;
        }

        if ($provider === []) {
            throw ConfigurationException::make('invalid_provider', 'A provider list must not be empty.');
        }

        $isList = array_is_list($provider);

        $seen = [];

        foreach ($provider as $alias => $configuredModel) {
            if ($isList) {
                $name = $configuredModel instanceof Lab ? $configuredModel->value : $configuredModel;

                if (! is_string($name) || trim($name) === '') {
                    throw ConfigurationException::make('invalid_provider', 'A provider list contains an invalid provider name.');
                }
            } else {
                if (! is_string($alias) || trim($alias) === '' || ! is_string($configuredModel) || trim($configuredModel) === '') {
                    throw ConfigurationException::make('invalid_provider', 'A provider map contains an invalid provider/model pair.');
                }

                $name = $alias;
            }

            $normalized = strtolower(trim($name));

            if (isset($seen[$normalized])) {
                throw ConfigurationException::make('invalid_provider', 'A provider list contains a duplicate provider.');
            }

            $seen[$normalized] = true;
        }
    }
}
