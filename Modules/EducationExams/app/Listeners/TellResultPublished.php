<?php

namespace Modules\EducationExams\Listeners;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\EducationExams\Events\ResultPublished;
use Modules\EducationExams\Services\ResultNotices;

/** A published result is told to each student's family when the institution asks for it (rule education_exams.notify_results). */
class TellResultPublished implements ShouldQueue
{
    public function __construct(private ResultNotices $notices, private ModuleResolver $modules) {}

    public function handle(ResultPublished $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company !== null && $this->modules->isEnabled('education_exams', $company)) {
            $this->notices->published($company, $event->sourceType, $event->sourceId);
        }
    }
}
