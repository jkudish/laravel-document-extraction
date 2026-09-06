<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\AgentResponse;

final class AiCallScope
{
    private ?string $invocationId = null;

    private ?TextProvider $startingProvider = null;

    private ?TextGenerationOptions $startingOptions = null;

    private bool $stepStarting = false;

    private ?NativeAiResult $result = null;

    private ?CompiledSchema $schema = null;

    private string $providerText = '';

    private string $retainedText = '';

    /** @param list<int> $pages */
    public function __construct(
        public readonly Agent $agent,
        public readonly AiExecutionSession $session,
        public readonly string $stage,
        public readonly array $pages,
    ) {}

    public function observePrompt(string $invocationId): bool
    {
        if ($this->invocationId === null) {
            $this->invocationId = $invocationId;

            return true;
        }

        if ($this->invocationId !== $invocationId) {
            if ($this->result !== null) {
                return false;
            }

            throw ConfigurationException::make(
                'unsupported_agent_reentry',
                'Extraction agent middleware must not prompt the same agent before forwarding the extraction invocation.',
            );
        }

        return true;
    }

    public function observeStartingStep(
        string $invocationId,
        TextProvider $provider,
        ?TextGenerationOptions $options,
    ): void {
        if ($this->invocationId !== $invocationId) {
            return;
        }

        $this->startingProvider = $provider;
        $this->startingOptions = $options;
        $this->stepStarting = true;
    }

    public function beginsGateway(TextProvider $provider, ?TextGenerationOptions $options): bool
    {
        if (! $this->stepStarting || $this->startingProvider !== $provider || $this->startingOptions !== $options) {
            return false;
        }

        $this->stepStarting = false;

        return true;
    }

    public function complete(
        NativeAiResult $result,
        ?CompiledSchema $schema,
        string $providerText,
        string $retainedText,
    ): void {
        $this->result = $result;
        $this->schema = $schema;
        $this->providerText = $providerText;
        $this->retainedText = $retainedText;
    }

    public function finish(AgentResponse $response): NativeAiResult
    {
        $this->session->remainingSeconds();

        if ($this->invocationId === null) {
            // A native middleware short circuit performs no provider dispatch.
            $this->schema = $this->agent instanceof HasStructuredOutput
                ? CompiledSchema::fromNative($this->agent->schema(new JsonSchemaTypeFactory))
                : null;
        } elseif ($this->invocationId !== $response->invocationId) {
            throw ConfigurationException::make(
                'unsupported_agent_reentry',
                'Extraction agent middleware must not prompt the same agent before forwarding the extraction invocation.',
            );
        } elseif ($this->result === null) {
            throw ConfigurationException::make(
                'unattributed_ai_invocation',
                'The native AI response could not be attributed to this extraction invocation.',
            );
        }

        if ($this->result !== null && $response->text === $this->providerText) {
            return $this->result;
        }

        $this->session->replaceRetained($this->retainedText, $response->text);
        $result = $this->schema === null
            ? new NativeAiResult(text: $response->text)
            : new NativeAiResult(data: $this->schema->validate($response->text));
        $this->session->remainingSeconds();

        return $result;
    }
}
