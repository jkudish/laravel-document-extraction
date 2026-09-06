<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction;

use Illuminate\Support\ServiceProvider;
use Jkudish\DocumentExtraction\Console\ExtractionDoctorCommand;

final class DocumentExtractionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/extraction.php', 'extraction');

        $this->app->singleton(DocumentExtraction::class);
        $this->app->alias(DocumentExtraction::class, 'document-extraction');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/extraction.php' => config_path('extraction.php'),
        ], 'document-extraction-config');

        if ($this->app->runningInConsole()) {
            $this->commands([ExtractionDoctorCommand::class]);
        }
    }
}
