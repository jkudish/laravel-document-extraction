<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Jkudish\DocumentExtraction\AI\Concerns\UsesExtractionConfiguration;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class InlineSchemaAgent implements Agent, HasMiddleware, HasProviderOptions, HasStructuredOutput
{
    use Promptable;
    use UsesExtractionConfiguration;

    /**
     * @param  Closure(JsonSchema): array<string, Type>  $schema
     * @param  array<string, array<string, mixed>>  $providerOptions
     * @param  array<mixed>  $middleware
     */
    public function __construct(
        private readonly Closure $schema,
        private readonly ?string $extractionInstructions,
        array $providerOptions,
        array $middleware,
    ) {
        $this->configureNativeAgent($providerOptions, $middleware);
    }

    public function instructions(): string
    {
        return $this->extractionInstructions
            ?? 'Extract only facts supported by the supplied document. Use null for unavailable nullable facts and return data matching the requested schema.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ($this->schema)($schema);
    }
}
