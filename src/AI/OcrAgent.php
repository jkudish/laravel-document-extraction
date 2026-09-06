<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Jkudish\DocumentExtraction\AI\Concerns\UsesExtractionConfiguration;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Promptable;

final class OcrAgent implements Agent, HasMiddleware, HasProviderOptions
{
    use Promptable;
    use UsesExtractionConfiguration;

    /**
     * @param  array<string, array<string, mixed>>  $providerOptions
     * @param  array<mixed>  $middleware
     */
    public function __construct(array $providerOptions, array $middleware)
    {
        $this->configureNativeAgent($providerOptions, $middleware);
    }

    public function instructions(): string
    {
        return 'Faithfully transcribe the visible document page. Preserve reading order and line breaks. Do not summarize, infer missing text, or add commentary.';
    }
}
