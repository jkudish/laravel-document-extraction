<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\AI;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Jkudish\DocumentExtraction\AI\Concerns\UsesExtractionConfiguration;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class DocumentDetectionAgent implements Agent, HasMiddleware, HasProviderOptions, HasStructuredOutput
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
        return "Identify separate logical documents among the supplied original physical pages. Assign a page only when its membership is clear. Include a blank or contentless page only when visible pagination or document continuity clearly establishes its membership; otherwise omit it from every group so it is reported as unassigned. Set a group's ambiguous flag only when document-bearing pages have genuinely uncertain membership or boundaries; do not use ambiguity for a page with no identifiable document. Never invent page numbers or document content.";
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'groups' => $schema->array()->items(
                $schema->object([
                    'pages' => $schema->array()->items($schema->integer()->min(1))->min(1)->required(),
                    'ambiguous' => $schema->boolean()->required(),
                ])
            )->required(),
        ];
    }
}
