<?php

namespace Modules\EducationLectures\Listeners;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationLectures\Events\LecturePublished;
use Modules\EducationLectures\Models\Lecture;
use Modules\EducationLectures\Services\Classroom;

/**
 * Tells the section's students and their guardians who have a login that a
 * lecture is up (mail, in each one's language), when the rule
 * education_lectures.notify_on_publish asks for it. People without a login
 * are not told: nobody pays for a message a day per lecture.
 */
class TellLecturePublished implements ShouldQueue
{
    public function __construct(
        private Classroom $classroom,
        private AcademicDirectory $academic,
        private Notifier $notifier,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function handle(LecturePublished $event): void
    {
        $company = Organization::query()->find($event->companyId);
        if ($company === null || ! $this->modules->isEnabled('education_lectures', $company)) {
            return;
        }
        $lecture = $this->classroom->query(Lecture::class, $company)->whereKey($event->lectureId)->where('status', 'published')->first();
        $unit = $lecture === null ? null : Organization::query()->find($lecture->unit_id);
        if ($lecture === null || $unit === null || ! $this->rules->get('education_lectures.notify_on_publish', $this->contexts->forOrganization($unit))) {
            return;
        }

        $students = $this->academic->sectionStudents($company, $lecture->section_id);
        $ids = array_column($students, 'id');
        $userIds = array_filter(array_column($students, 'user_id'));
        foreach ($this->academic->noticeContacts($company, $ids) as $contacts) {
            $userIds = [...$userIds, ...array_filter(array_column($contacts, 'user_id'))];
        }
        // Whoever the portal links to these students (the student themselves, guardians).
        $userIds = [...$userIds, ...PortalLink::query()->where('organization_id', $company->getKey())->where('subject_type', 'education.student')
            ->whereIn('subject_id', $ids)->where('status', PortalLink::ACTIVE)->pluck('user_id')->filter()->all()];
        $users = User::query()->whereKey(array_values(array_unique($userIds)))->get();
        if ($users->isEmpty()) {
            return;
        }

        $subject = $this->academic->subjects($company, [$lecture->subject_id])[$lecture->subject_id] ?? null;
        $teacher = $lecture->teacher_id === null ? '' : ($this->academic->teacherNames($company, [$lecture->teacher_id])[$lecture->teacher_id] ?? '');
        $this->notifier->notify('education_lectures.published', $users, fn (string $locale) => [
            'organization' => $company->displayName($locale),
            'subject' => $subject === null ? '' : (string) ($subject['name'][$locale] ?? $subject['name']['en'] ?? (reset($subject['name']) ?: '')),
            'title' => $lecture->title,
            'teacher' => $teacher,
        ], $company->partner, $company);
    }
}
