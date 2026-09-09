<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenRouterGenerationMetadata
{
    /** @var list<int> */
    public const array RETRY_DELAYS_MS = [1000, 2000, 4000, 8000, 15000];

    public static function fetch(string $key, string $generationId, bool $withoutDelay = false): Response
    {
        return Http::withToken($key)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(
                self::retryDelays($withoutDelay),
                when: static fn (Throwable $exception): bool => $exception instanceof RequestException
                    && $exception->response->status() === 404,
                throw: false,
            )
            ->get('https://openrouter.ai/api/v1/generation', ['id' => $generationId])
            ->throw();
    }

    /** @return list<int> */
    public static function retryDelays(bool $withoutDelay = false): array
    {
        return $withoutDelay
            ? array_fill(0, count(self::RETRY_DELAYS_MS), 0)
            : self::RETRY_DELAYS_MS;
    }
}
