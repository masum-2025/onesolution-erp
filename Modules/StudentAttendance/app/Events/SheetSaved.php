<?php

namespace Modules\StudentAttendance\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A section's sheet of today was saved (new or changed). Carries ids only. */
final class SheetSaved
{
    use Dispatchable;

    public function __construct(public string $companyId, public string $sheetId) {}
}
