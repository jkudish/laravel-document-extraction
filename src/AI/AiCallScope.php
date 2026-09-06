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
use Laravel\Ai\Responses\StructuredAgentResponse;

final class AiCallScope
{
    private ?string $invocationId = null;

    private ?TextProvider $startingProvider = null;

    private ?TextGenerationOptions $startingOptions = null;

    private bool $stepStarting = false;

    private ?NativeAiResult $result = null;

    private ?CompiledSchema $schema = null;

    private string $providerText = '';

    /** @var array<string, mixed>|null */
    private ?array $providerStructured = null;

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

    /** @param array<string, mixed>|null $providerStructured */
    public function complete(
        NativeAiResult $result,
        ?CompiledSchema $schema,
        string $providerText,
        string $retainedText,
        ?array $providerStructured,
    ): void {
        $this->result = $result;
        $this->schema = $schema;
        $this->providerText = $providerText;
        $this->retainedText = $retainedText;
        $this->providerStructured = $providerStructured;
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

        $text = $response->text;

        if ($this->result !== null && $text === $this->providerText) {
            if ($this->schema === null || ! $response instanceof StructuredAgentResponse || $response->structured === $this->providerStructured) {
                return $this->result;
            }

            // Provider JSON was already validated; preserve unchanged containers while applying PHP middleware edits.
            try {
                $edited = $this->restoreUnchangedJson(
                    $response->structured,
                    $this->providerStructured,
                    json_decode($this->retainedText, false, 512, JSON_THROW_ON_ERROR),
                );
                $text = json_encode($edited === [] ? new \stdClass : $edited, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InvalidAiOutputException('The middleware returned data that cannot be represented as JSON.');
            }
        }

        $this->session->replaceRetained($this->retainedText, $text);
        $result = $this->schema === null
            ? new NativeAiResult(text: $text)
            : new NativeAiResult(data: $this->schema->validate($text));
        $this->session->remainingSeconds();

        return $result;
    }

    private function restoreUnchangedJson(mixed $current, mixed $baseline, mixed $original): mixed
    {
        if ($current === $baseline) {
            return $original;
        }

        if (! is_array($current) || ! is_array($baseline)) {
            return $current;
        }

        $originalChildren = (array) $original;

        if (array_diff_key($baseline, $originalChildren) !== [] || array_diff_key($originalChildren, $baseline) !== []) {
            throw new InvalidAiOutputException('The normalized response shape cannot be reconciled with its original JSON.');
        }

        foreach ($current as $key => $value) {
            if (array_key_exists($key, $baseline)) {
                $current[$key] = $this->restoreUnchangedJson($value, $baseline[$key], $originalChildren[$key]);
            }
        }

        return $current;
    }
}
