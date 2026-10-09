<?php

namespace Modules\Education\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Models\Admission;
use Modules\Education\Models\Student;
use Modules\Education\Services\Education;

/**
 * Education on the dashboard and in the bell, for the unit in the context
 * and below: students now; applications waiting for a decision (for people
 * who admit).
 */
final class EducationWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        [$company, $units] = $this->scope($context);
        if ($company === null) {
            return WidgetData::stat(0);
        }
        $education = app(Education::class);
        $active = $education->query(Student::class, $company)->whereIn('unit_id', $units)->where('status', 'active')->count();
        $waiting = $education->query(Admission::class, $company)->whereIn('unit_id', $units)->whereIn('status', ['applied', 'test', 'offered'])->count();

        return WidgetData::stat($active, hint: __('education::dashboard.students_hint', ['count' => $waiting]));
    }

    public function items(CurrentContext $context): array
    {
        [$company, $units] = $this->scope($context);
        if ($company === null || ! Gate::allows('education.admit', $context->organization())) {
            return [];
        }
        $waiting = app(Education::class)->query(Admission::class, $company)->whereIn('unit_id', $units)->whereIn('status', ['applied', 'test', 'offered'])->count();

        return $waiting === 0 ? [] : [new AttentionItem('education.admissions', __('education::dashboard.attention_admissions'), $waiting, '/education/admissions', 'info')];
    }

    /**
     * @return array{0: Organization|null, 1: list<string>}
     */
    private function scope(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return [null, []];
        }
        $education = app(Education::class);

        return [$education->companyOf($unit), $education->subtreeIds($unit)];
    }
}
