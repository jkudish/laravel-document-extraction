<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Preparation;

final readonly class PreparedDocument
{
    /**
     * @param  list<PreparedPage>  $pages
     * @param  list<int>|null  $selectedPages
     */
    public function __construct(
        public string $mediaType,
        public ?int $pageCount,
        public array $pages,
        public ?array $selectedPages,
        public ?string $directText = null,
    ) {}

    public function requiresAi(): bool
    {
        foreach ($this->pages as $page) {
            if ($page->needsOcr) {
                return true;
            }
        }

        return false;
    }

    public function hasVisualPages(): bool
    {
        foreach ($this->pages as $page) {
            if ($page->visualPath !== null) {
                return true;
            }
        }

        return false;
    }

    public function inlineAttachmentBytes(): int
    {
        $bytes = 0;

        foreach ($this->pages as $page) {
            $bytes += $page->visualBytes ?? 0;
        }

        return $bytes;
    }
}
