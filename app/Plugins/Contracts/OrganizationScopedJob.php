<?php

namespace App\Plugins\Contracts;

/**
 * Marker for plugin jobs that run in one organization context.
 * ADR-0001 D14: carry organization_id and use SetsOrganizationContext when that lands.
 */
interface OrganizationScopedJob
{
    //
}
