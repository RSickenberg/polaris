<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

use Sentry\Breadcrumb;

/**
 * `before_breadcrumb`: scrubs a breadcrumb. It is written before anyone knows whose report it will join, so nothing is
 * decided here: the report it ends up in is held back or sent by {@see EventFilter}.
 */
final readonly class BreadcrumbFilter
{
    public function __construct(private Scrubber $scrubber)
    {
    }

    public function __invoke(Breadcrumb $breadcrumb): Breadcrumb
    {
        return $this->scrubber->scrubBreadcrumb($breadcrumb);
    }
}
