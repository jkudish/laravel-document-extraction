<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction;

use Closure;
use Jkudish\DocumentExtraction\Source\SourceInput;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;

/** @phpstan-import-type ProviderRoute from PendingExtraction
 * @phpstan-import-type ExtractionConfiguration from PendingExtraction
 */
final readonly class ExtractionInvocation
{
    /**
     * @param  list<int>  $pages
     * @param  ProviderRoute  $provider
     * @param  ProviderRoute  $detectionProvider
     * @param  ExtractionConfiguration  $configuration
     */
    public function __construct(
        public SourceInput $source,
        public TerminalOperation $operation,
        public ?Closure $schema,
        public Agent|string|null $agent,
        public ?string $instructions,
        public bool $detectDocuments,
        public array $pages,
        public bool $withoutAi,
        public Lab|array|string|null $provider,
        public ?string $model,
        public ?int $timeout,
        public Lab|array|string|null $detectionProvider,
        public ?string $detectionModel,
        public ?int $detectionTimeout,
        public array $configuration,
    ) {}
}
