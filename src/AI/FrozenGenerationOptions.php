<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;

/** Keeps the checked native provider options identical to those used by the gateway. */
final class FrozenGenerationOptions extends TextGenerationOptions
{
    /** @param array<string, mixed> $providerOptions */
    public function __construct(TextGenerationOptions $options, private readonly array $providerOptions)
    {
        foreach ([
            'model', 'modelId', 'input', 'messages', 'contents', 'system', 'system_instruction',
            'tools', 'tool_choice', 'tool_config', 'toolConfig', 'text', 'response_format',
            'output_config', 'generationConfig', 'response_mime_type', 'response_json_schema',
            'responseMimeType', 'responseSchema', 'responseJsonSchema', 'stream',
            'previous_response_id', 'conversation', 'cachedContent', 'container',
        ] as $key) {
            if (array_key_exists($key, $providerOptions)) {
                throw ConfigurationException::make(
                    'unsupported_provider_options',
                    'Extraction provider options must not replace the native request structure or conversation state.',
                );
            }
        }

        parent::__construct(
            maxSteps: $options->maxSteps,
            maxTokens: $options->maxTokens,
            temperature: $options->temperature,
            agent: $options->agent,
            topP: $options->topP,
            toolChoice: $options->toolChoice,
            cacheInstructions: $options->cacheInstructions,
            cacheToolDefinitions: $options->cacheToolDefinitions,
        );
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions;
    }
}
