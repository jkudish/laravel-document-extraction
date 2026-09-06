<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Jkudish\DocumentExtraction\DocumentExtractionServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            DocumentExtractionServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('images.default', 'imagick');
    }
}
