<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Tests\Support;

enum GroupingMetric: string
{
    case ExactGroups = 'exact-group-match';
    case MergeErrors = 'merge-errors';
    case SplitErrors = 'split-errors';
    case SelectedPageCoverage = 'selected-page-coverage';
    case Ambiguity = 'ambiguity';
    case UnassignedPages = 'unassigned-pages';
    case SchemaFailures = 'schema-failures';
    case MembershipFailures = 'membership-failures';
    case ProviderFailures = 'provider-failures';
}
