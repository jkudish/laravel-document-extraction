<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Illuminate\Contracts\Events\Dispatcher;
use Jkudish\DocumentExtraction\Exceptions\ConfigurationException;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;

final readonly class NativeAiBridge
{
    public function __construct(
        private InvocationScopeRegistry $scopes,
        Dispatcher $events,
    ) {
        $events->listen(PromptingAgent::class, $this->prompting(...));
        $events->listen(StartingStep::class, $this->starting(...));
    }

    public function scopes(): InvocationScopeRegistry
    {
        return $this->scopes;
    }

    private function prompting(PromptingAgent $event): void
    {
        if ($this->scopes->prompting($event->prompt->agent, $event->invocationId) === null) {
            return;
        }

        $provider = $event->prompt->provider();
        $gateway = $this->gateway($provider);

        if ($gateway instanceof ScopedTextGateway && $gateway->uses($this->scopes)) {
            return;
        }

        $provider->useTextGateway(new ScopedTextGateway($gateway, $this->scopes));
    }

    private function starting(StartingStep $event): void
    {
        $this->scopes->starting(
            $event->agent,
            $event->invocationId,
            $event->provider,
            $event->options,
        );
    }

    private function gateway(TextProvider $provider): StepTextGateway
    {
        if (! method_exists($provider, 'textGateway')) {
            throw ConfigurationException::make(
                'unsupported_ai_provider',
                'The selected Laravel AI provider does not expose its public text gateway.',
            );
        }

        $gateway = $provider->textGateway();

        if (! $gateway instanceof StepTextGateway) {
            throw ConfigurationException::make(
                'unsupported_ai_provider',
                'The selected Laravel AI provider returned an invalid text gateway.',
            );
        }

        return $gateway;
    }
}
