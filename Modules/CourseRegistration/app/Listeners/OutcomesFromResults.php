<?php

namespace Modules\CourseRegistration\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\CourseRegistration\Models\RegistrationItem;
use Modules\CourseRegistration\Services\Campus;
use Modules\CourseRegistration\Services\Registrations;
use Modules\EducationExams\Directory\ResultDirectory;
use Modules\EducationExams\Events\ResultPublished;

/**
 * Published exam results set the outcome of the subjects students
 * registered for in that session (completed or failed), through the exams
 * module's public ResultDirectory; prerequisites then check them. A
 * withdrawn subject keeps its outcome. Queued: publishing never waits.
 */
class OutcomesFromResults implements ShouldQueue
{
    public function __construct(private Campus $campus, private Registrations $registrations, private ModuleResolver $modules) {}

    public function handle(ResultPublished $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company === null || ! $this->modules->isEnabled('course_registration', $company)) {
            return;
        }
        $outcomes = app(ResultDirectory::class)->subjectOutcomes($company, $event->sourceType, $event->sourceId);
        if ($outcomes === []) {
            return;
        }
        $items = $this->campus->query(RegistrationItem::class, $company)->where('session_id', $event->sessionId)->where('status', 'registered')
            ->whereIn('student_id', array_keys($outcomes))->get();
        foreach ($items as $item) {
            $passed = $outcomes[$item->student_id][$item->subject_id] ?? null;
            if ($passed !== null) {
                $this->registrations->recordOutcome($company, $item, $passed ? 'completed' : 'failed', null);
            }
        }
    }
}
