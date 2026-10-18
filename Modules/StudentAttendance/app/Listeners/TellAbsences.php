<?php

namespace Modules\StudentAttendance\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\StudentAttendance\Events\SheetSaved;
use Modules\StudentAttendance\Services\AbsenceNotices;

/**
 * Tells families about today's absences a few minutes after the sheet is
 * saved (rule student_attendance.notify_absent: delay_minutes), so a
 * mistake put right in that time sends nothing. Nothing while the module
 * is off.
 */
class TellAbsences implements ShouldQueue
{
    public function __construct(private AbsenceNotices $notices, private ModuleResolver $modules) {}

    /** Asked while queuing, on an instance built without its constructor: services come from the container. */
    public function withDelay(SheetSaved $event): int
    {
        $company = Organization::query()->find($event->companyId);

        return $company === null ? 0 : max(0, app(AbsenceNotices::class)->rule($company)['delay_minutes']) * 60;
    }

    public function handle(SheetSaved $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company === null || ! $this->modules->isEnabled('student_attendance', $company)) {
            return;
        }
        $this->notices->forSheet($company, $event->sheetId);
    }
}
