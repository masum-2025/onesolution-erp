<?php

namespace App\Platform\DataExport\Events;

use App\Platform\DataExport\Models\DataExport;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A data export finished and can be downloaded.
 */
class DataExportReady implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public DataExport $export) {}
}
