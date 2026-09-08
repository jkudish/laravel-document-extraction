<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Jkudish\DocumentExtraction\Tests\Support\OpenRouterGenerationMetadata;

it('polls only eventual generation metadata misses with the bounded production backoff', function (string $scenario): void {
    [$statuses, $expectedStatus, $expectedRequests, $expectedToThrow] = match ($scenario) {
        'eventual success' => [[404, 200], 200, 2, false],
        'non-retryable failure' => [[500, 200], 500, 1, true],
        'exhausted eventual misses' => [[404, 404, 404, 404, 404, 404], 404, 6, true],
        default => throw new LogicException("Unknown metadata scenario [{$scenario}]."),
    };
    $sequence = Http::sequence();

    foreach ($statuses as $status) {
        $sequence->pushStatus($status);
    }

    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/generation*' => $sequence]);

    $response = null;
    $exception = null;

    try {
        $response = OpenRouterGenerationMetadata::fetch('test-key', 'gen-test', withoutDelay: true);
    } catch (RequestException $caught) {
        $exception = $caught;
    }

    $status = $response?->status() ?? $exception?->response->status();

    expect($status)->toBe($expectedStatus)
        ->and($exception instanceof RequestException)->toBe($expectedToThrow)
        ->and(Http::recorded())->toHaveCount($expectedRequests)
        ->and(OpenRouterGenerationMetadata::retryDelays())->toBe([1000, 2000, 4000, 8000, 15000])
        ->and(OpenRouterGenerationMetadata::retryDelays(withoutDelay: true))->toBe([0, 0, 0, 0, 0]);

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/generation?id=gen-test');
})->with([
    'eventual success',
    'non-retryable failure',
    'exhausted eventual misses',
]);
