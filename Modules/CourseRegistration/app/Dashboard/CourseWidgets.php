<?php

namespace Modules\CourseRegistration\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\CourseRegistration\Models\Registration;
use Modules\CourseRegistration\Services\Campus;

/**
 * Registrations handed in and waiting to be approved: a count on the
 * dashboard, and in the bell for those who approve (never their own hand-in).
 */
final class CourseWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = self::place($context);
        if ($place === null) {
            return WidgetData::stat(0);
        }
        [$company, $units] = $place;
        $waiting = app(Campus::class)->query(Registration::class, $company)->whereIn('unit_id', $units)->where('status', 'submitted')->count();

        return WidgetData::stat($waiting, hint: __('course_registration::dashboard.waiting_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $place = self::place($context);
        if ($place === null || ! Gate::allows('course_registration.approve', $context->organization())) {
            return [];
        }
        [$company, $units] = $place;
        $me = $context->user()?->getKey();
        $waiting = app(Campus::class)->query(Registration::class, $company)->whereIn('unit_id', $units)->where('status', 'submitted')
            ->where(fn ($query) => $query->whereNull('submitted_by')->orWhere('submitted_by', '!=', $me))->count();

        return $waiting === 0 ? [] : [new AttentionItem('course_registration.waiting', __('course_registration::dashboard.attention_waiting'), $waiting, '/course-registration/registrations', 'warn')];
    }

    /**
     * The institution and the units of the context (a group or department registers no one).
     *
     * @return array{0: Organization, 1: list<string>}|null
     */
    public static function place(CurrentContext $context): ?array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group || $unit->type === OrganizationType::Department) {
            return null;
        }
        $campus = app(Campus::class);

        return [$campus->companyOf($unit), $campus->subtreeIds($unit)];
    }
}
