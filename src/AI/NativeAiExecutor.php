<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Jkudish\DocumentExtraction\Results\EvidenceOrigin;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;

final readonly class NativeAiExecutor
{
    public function __construct(private NativeAiBridge $bridge) {}

    /**
     * @param  list<int>  $pages
     * @param  array<mixed>|Lab|string|null  $provider
     * @param  array<mixed>  $attachments
     */
    public function prompt(
        AiExecutionSession $session,
        Agent $agent,
        string $stage,
        array $pages,
        string $prompt,
        array $attachments,
        Lab|array|string|null $provider,
        ?string $model,
        ?int $timeout,
    ): NativeAiResult {
        $scope = new AiCallScope(
            $agent,
            $session,
            $stage,
            $pages,
            Ai::hasFakeGatewayFor($agent::class) ? EvidenceOrigin::Simulated : EvidenceOrigin::Live,
        );

        /** @var NativeAiResult $result */
        $result = $this->bridge->scopes()->run(
            $scope,
            function () use ($scope, $agent, $prompt, $attachments, $provider, $model, $timeout): NativeAiResult {
                $response = $agent->prompt($prompt, $attachments, $provider, $model, $timeout);

                return $scope->finish($response);
            },
        );

        return $result;
    }
}
