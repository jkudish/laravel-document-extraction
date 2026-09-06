<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI\Concerns;

use Laravel\Ai\Enums\Lab;

trait UsesExtractionConfiguration
{
    /** @var array<string, array<string, mixed>> */
    private array $providerOptions = [];

    /** @var array<mixed> */
    private array $promptMiddleware = [];

    /**
     * @param  array<string, array<string, mixed>>  $providerOptions
     * @param  array<mixed>  $middleware
     */
    private function configureNativeAgent(array $providerOptions, array $middleware): void
    {
        $this->providerOptions = $providerOptions;
        $this->promptMiddleware = $middleware;
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;

        return $this->providerOptions[$name] ?? $this->providerOptions['*'] ?? [];
    }

    /** @return array<mixed> */
    public function middleware(): array
    {
        return $this->promptMiddleware;
    }
}
