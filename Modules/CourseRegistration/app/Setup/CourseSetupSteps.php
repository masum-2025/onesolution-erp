<?php

namespace Modules\CourseRegistration\Setup;

use App\Platform\Tenancy\Models\Organization;
use Modules\CourseRegistration\Models\Offering;
use Modules\CourseRegistration\Models\Window;
use Modules\CourseRegistration\Services\Campus;
use Modules\Education\Contracts\SetupStepProvider;

/**
 * Course registration on the setup checklist: the subjects offered in the
 * open session, and the dates registration is open.
 */
class CourseSetupSteps implements SetupStepProvider
{
    public function __construct(private Campus $campus) {}

    public function module(): string
    {
        return 'course_registration';
    }

    public function steps(Organization $company, ?string $sessionId, array $unitIds): array
    {
        $offered = $sessionId === null ? 0 : $this->campus->query(Offering::class, $company)->where('session_id', $sessionId)->where('status', '!=', 'cancelled')->count();
        $window = $sessionId !== null && $this->campus->query(Window::class, $company)->where('session_id', $sessionId)->exists();
        $text = fn (string $key) => __("course_registration::setup.steps.{$key}");

        return [
            ['key' => 'offerings', 'label' => $text('offerings.label'), 'route' => '/course-registration', 'done' => $offered > 0, 'detail' => $offered > 0 ? null : $text('offerings.missing')],
            ['key' => 'window', 'label' => $text('window.label'), 'route' => '/course-registration', 'done' => $window, 'detail' => $window ? null : $text('window.missing')],
        ];
    }
}
