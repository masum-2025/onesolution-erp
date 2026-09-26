<?php

namespace App\Platform\DataExport\Contracts;

use App\Platform\Tenancy\Models\Organization;

/**
 * Implemented by a module that owns data, and tagged in its service provider:
 *
 *     $this->app->tag([ExportPayrollData::class], 'module.exporters');
 *
 * The export never reads a module's tables itself: each module hands over
 * its own rows. Never include secrets (passwords, tokens, keys).
 */
interface ExportsModuleData
{
    /** The module key (used as the folder name in the export). */
    public function moduleKey(): string;

    /**
     * The module's data for the organization and every unit below it.
     *
     * @param  list<string>  $organizationIds  The organization and its descendants.
     * @return array<string, iterable<array<string, scalar|null>>> Dataset name => rows.
     */
    public function export(Organization $organization, array $organizationIds): array;
}
