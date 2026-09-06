<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;

final class AiCallScope
{
    private ?string $invocationId = null;

    private ?TextProvider $startingProvider = null;

    private ?TextGenerationOptions $startingOptions = null;

    private bool $stepStarting = false;

    private bool $completed = false;

    private ?NativeAiResult $result = null;

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

    public function complete(NativeAiResult $result): void
    {
        $this->result = $result;
    }

    public function finish(string $responseInvocationId): NativeAiResult
    {
        if ($this->invocationId === null || $this->invocationId !== $responseInvocationId || $this->result === null) {
            throw ConfigurationException::make(
                'unattributed_ai_invocation',
                'The native AI response could not be attributed to this extraction invocation.',
            );
        }

        $this->completed = true;

        return $this->result;
    }

    public function completed(): bool
    {
        return $this->completed;
    }
}
