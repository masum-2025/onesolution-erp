<?php

namespace App\Platform\DataExport\Jobs;

use App\Platform\DataExport\Models\DataExport;
use App\Platform\DataExport\Services\DataExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds one data export in the background. Runs without a tenant context:
 * every query in ExportBuilder is limited to the export's organization tree.
 */
class BuildDataExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $exportId) {}

    public function handle(DataExportService $exports): void
    {
        $export = DataExport::query()->with('organization')->find($this->exportId);

        if ($export !== null && $export->status === DataExport::QUEUED) {
            $exports->build($export);
        }
    }
}
