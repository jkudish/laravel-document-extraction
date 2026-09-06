<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Fiber;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;

final class InvocationScopeRegistry
{
    /** @var array<string, list<AiCallScope>> */
    private array $scopes = [];

    public function run(AiCallScope $scope, callable $callback): mixed
    {
        $key = $this->key();
        $this->scopes[$key] ??= [];
        $this->scopes[$key][] = $scope;

        try {
            return $callback();
        } finally {
            array_pop($this->scopes[$key]);

            if ($this->scopes[$key] === []) {
                unset($this->scopes[$key]);
            }
        }
    }

    public function prompting(
        Agent $agent,
        string $invocationId,
        string $provider,
        string $model,
    ): ?AiCallScope {
        $scope = $this->current();

        if ($scope === null || $scope->agent !== $agent) {
            return null;
        }

        return $scope->observePrompt($invocationId, $provider, $model) ? $scope : null;
    }

    public function starting(
        Agent $agent,
        string $invocationId,
        TextProvider $provider,
        ?TextGenerationOptions $options,
    ): void {
        $scope = $this->current();

        if ($scope !== null && $scope->agent === $agent) {
            $scope->observeStartingStep($invocationId, $provider, $options);
        }
    }

    public function gateway(TextProvider $provider, ?TextGenerationOptions $options): ?AiCallScope
    {
        $scope = $this->current();

        return $scope !== null && $scope->beginsGateway($provider, $options) ? $scope : null;
    }

    private function current(): ?AiCallScope
    {
        $stack = $this->scopes[$this->key()] ?? [];

        return $stack === [] ? null : $stack[array_key_last($stack)];
    }

    private function key(): string
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? 'main' : 'fiber:'.spl_object_id($fiber);
    }
}
