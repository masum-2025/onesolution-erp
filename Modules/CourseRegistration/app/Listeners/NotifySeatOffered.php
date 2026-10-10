<?php

namespace Modules\CourseRegistration\Listeners;

use App\Models\User;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Tenancy\Models\Organization;
use Modules\CourseRegistration\Events\SeatOffered;
use Modules\CourseRegistration\Models\Offering;
use Modules\CourseRegistration\Services\Campus;
use Modules\Education\Directory\AcademicDirectory;

/**
 * Tells a student who moved up from a waiting list that they now hold a
 * seat (mail or SMS, in their language), when their login is linked to the
 * student record. Without a login there is nobody to tell.
 */
class NotifySeatOffered
{
    public function __construct(private Campus $campus, private AcademicDirectory $academic, private Notifier $notifier) {}

    public function handle(SeatOffered $event): void
    {
        $company = Organization::query()->find($event->companyId);
        $student = $company === null ? null : $this->academic->student($company, $event->studentId);
        $user = $student['user_id'] ?? null ? User::query()->find($student['user_id']) : null;
        $offering = $company === null ? null : $this->campus->query(Offering::class, $company)->whereKey($event->offeringId)->first();
        if ($user === null || $offering === null) {
            return;
        }
        $subject = $this->academic->subjects($company, [$offering->subject_id])[$offering->subject_id] ?? null;
        $session = $this->academic->session($company, $offering->session_id);

        $this->notifier->notify('course_registration.seat_offered', [$user], fn (string $locale) => [
            'organization' => $company->displayName($locale),
            'subject' => trim(($subject['code'] ?? '').' '.($subject['name'][$locale] ?? $subject['name']['en'] ?? '')),
            'session' => (string) ($session['name'][$locale] ?? $session['name']['en'] ?? ''),
        ], $company->partner, $company);
    }
}
