<?php

namespace App\Platform\Audit\Exports;

use App\Platform\Modules\Jobs\EnsureModuleEnabledForJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds one audit export in the background, only while advanced_audit is on
 * for the organization. Every query is limited to its organization tree.
 */
class BuildAuditExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $exportId, public string $organizationId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new EnsureModuleEnabledForJob('advanced_audit', $this->organizationId)];
    }

    public function handle(AuditExportService $exports): void
    {
        $export = AuditExport::query()->with('organization')->find($this->exportId);

        if ($export !== null && $export->status === AuditExport::QUEUED) {
            $exports->build($export);
        }
    }
}
