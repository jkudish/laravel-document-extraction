<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Generator;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Throwable;

final readonly class ScopedTextGateway implements StepTextGateway
{
    public function __construct(
        private StepTextGateway $gateway,
        private InvocationScopeRegistry $scopes,
    ) {}

    public function uses(InvocationScopeRegistry $scopes): bool
    {
        return $this->scopes === $scopes;
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $scope = $this->scopes->gateway($provider, $options);

        if ($scope === null) {
            return $this->gateway->generateTextStep(
                $provider,
                $model,
                $instructions,
                $messages,
                $tools,
                $schema,
                $options,
                $timeout,
                $stepContext,
            );
        }

        $remaining = $scope->session->beginAttempt();
        $resolvedTimeout = $timeout === null ? $remaining : min($timeout, $remaining);
        $compiled = $schema === null ? null : CompiledSchema::fromNative($schema);
        $startedAt = hrtime(true);

        try {
            $response = $this->gateway->generateTextStep(
                $provider,
                $model,
                $instructions,
                $messages,
                $tools,
                $schema,
                $options,
                $resolvedTimeout,
                $stepContext,
            );

            $scope->session->retain($response->text);

            if ($compiled === null) {
                $scope->complete(new NativeAiResult(text: $response->text));
            } else {
                $scope->complete(new NativeAiResult(data: $compiled->validate($response->text)));
            }

            $scope->session->record(
                $scope->stage,
                $scope->pages,
                $provider->name(),
                $model,
                'succeeded',
                $this->elapsedMilliseconds($startedAt),
            );

            return $response;
        } catch (Throwable $exception) {
            $scope->session->record(
                $scope->stage,
                $scope->pages,
                $provider->name(),
                $model,
                $exception instanceof InvalidAiOutputException ? 'invalid_output' : 'failed',
                $this->elapsedMilliseconds($startedAt),
            );

            throw $exception;
        }
    }

    /**
     * Package extraction is synchronous; unrelated native streams pass through untouched.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     * @return Generator<int, mixed, mixed, StepResponse|null>
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        return yield from $this->gateway->generateStreamStep(
            $invocationId,
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
