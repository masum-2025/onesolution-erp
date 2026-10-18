<?php

namespace Modules\StudentAttendance\Dashboard;

use App\Models\User;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Services\Register;
use Modules\StudentAttendance\Services\Sheets;

/**
 * Where the attendance widgets look from the context: the institution, the
 * units of the context, and the open sessions' sections the person sees.
 * A group or department has no classes of its own.
 */
final class DashboardPlace
{
    /**
     * @param  list<string>  $units
     * @param  Collection<int, array<string, mixed>>  $sections
     */
    private function __construct(public Organization $company, public Organization $unit, public User $user, public array $units, public Collection $sections) {}

    public static function of(CurrentContext $context): ?self
    {
        $unit = $context->organization();
        $user = $context->user();
        if ($user === null || $unit->type === OrganizationType::Group || $unit->type === OrganizationType::Department) {
            return null;
        }
        $register = app(Register::class);
        $academic = app(AcademicDirectory::class);
        $company = $register->companyOf($unit);
        $units = $register->subtreeIds($unit);
        $visible = app(Sheets::class)->visibleSections($company, $unit, $user);
        $sections = collect($academic->sessions($company))->where('status', 'open')
            ->flatMap(fn (array $session) => $academic->sessionSections($company, $session['id'], $units))
            ->when($visible !== null, fn (Collection $all) => $all->whereIn('id', $visible))
            ->where('students', '>', 0)->values();

        return new self($company, $unit, $user, $units, $sections);
    }
}
