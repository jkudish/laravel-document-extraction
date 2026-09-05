<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Facades;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Facade;
use Jkudish\DocumentExtraction\DocumentExtraction;
use Jkudish\DocumentExtraction\ExtractionInvocation;
use Jkudish\DocumentExtraction\Fakes\FakeDocumentExtraction;
use Jkudish\DocumentExtraction\Results\ExtractionResult;
use RuntimeException;

/**
 * @method static \Jkudish\DocumentExtraction\PendingExtraction fromPath(string $path)
 * @method static \Jkudish\DocumentExtraction\PendingExtraction fromStorage(string $path, ?string $disk = null)
 * @method static \Jkudish\DocumentExtraction\PendingExtraction fromUpload(\Illuminate\Http\UploadedFile $file)
 * @method static \Jkudish\DocumentExtraction\PendingExtraction fromStream(mixed $stream, ?string $mimeType = null)
 * @method static \Jkudish\DocumentExtraction\PendingExtraction fromString(string $contents, ?string $mimeType = null)
 * @method static void assertCalled(callable|int|null $callback = null)
 * @method static void assertNothingCalled()
 *
 * @see DocumentExtraction
 */
final class Extraction extends Facade
{
    /**
     * @param  list<ExtractionResult>|Closure(ExtractionInvocation): ExtractionResult  $results
     */
    public static function fake(array|Closure $results = []): FakeDocumentExtraction
    {
        $application = self::getFacadeApplication();

        if ($application === null) {
            throw new RuntimeException('The Extraction facade has not been assigned a Laravel application.');
        }

        $fake = new FakeDocumentExtraction(
            $application->make(Repository::class),
            $application->make(FilesystemFactory::class),
            $application->make(Container::class),
            $results,
        );

        $application->instance(DocumentExtraction::class, $fake);
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'document-extraction';
    }
}
