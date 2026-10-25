<?php

namespace App\Plugins\Contracts;

/**
 * Marker for plugin jobs that touch only global (non–org-scoped) tables.
 * ADR-0001 D14: every packages/* job is either GlobalJob or OrganizationScopedJob.
 */
interface GlobalJob
{
    //
}
