<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Source;

enum SourceType: string
{
    case Path = 'path';
    case Storage = 'storage';
    case Upload = 'upload';
    case Stream = 'stream';
    case String = 'string';
}
