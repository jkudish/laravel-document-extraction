<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Facades;

use Illuminate\Support\Facades\Facade;
use Jkudish\DocumentExtraction\DocumentExtraction;

/** @see DocumentExtraction */
final class Extraction extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'document-extraction';
    }
}
