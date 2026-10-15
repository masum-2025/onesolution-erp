<?php

namespace Modules\CourseRegistration\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Number;
use Modules\CourseRegistration\Models\Registration;
use Modules\CourseRegistration\Services\Campus;
use Modules\CourseRegistration\Services\Registrations;
use Modules\Education\Directory\AcademicDirectory;

/**
 * The open session's registrations, with where its registration window
 * stands in words: opens in N days, open for N more days, closed, or not
 * set yet.
 */
final class RegistrationWindow implements DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = CourseWidgets::place($context);
        if ($place === null) {
            return WidgetData::stat(0);
        }
        [$company, $units] = $place;
        $session = collect(app(AcademicDirectory::class)->sessions($company))->firstWhere('status', 'open');
        if ($session === null) {
            return WidgetData::stat(0, hint: __('course_registration::dashboard.window_no_session'));
        }
        $count = app(Campus::class)->query(Registration::class, $company)->where('session_id', $session['id'])->whereIn('unit_id', $units)->count();

        return WidgetData::stat($count, hint: $this->windowText($company, $session['id']));
    }

    private function windowText($company, string $sessionId): string
    {
        $window = app(Registrations::class)->window($company, $sessionId);
        if ($window === null) {
            return __('course_registration::dashboard.window_unset');
        }
        $today = app(Campus::class)->today($company)->startOfDay();
        $days = fn ($date) => Number::format((int) $today->diffInDays($date->startOfDay()), locale: app()->getLocale());

        return match (true) {
            $window->opens_on->greaterThan($today) => __('course_registration::dashboard.window_opens', ['days' => $days($window->opens_on)]),
            $window->closes_on->greaterThanOrEqualTo($today) => __('course_registration::dashboard.window_open', ['days' => $days($window->closes_on)]),
            default => __('course_registration::dashboard.window_closed'),
        };
    }
}
