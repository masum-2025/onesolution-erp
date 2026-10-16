<?php

namespace Modules\EducationLectures\Dashboard;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Modules\EducationLectures\Services\Classroom;
use Modules\EducationLectures\Services\Lectures;

/**
 * Where the lecture widgets look from the context: the institution, the
 * units of the context and the sections the person sees (null: all).
 * A group or department has no classes of its own.
 */
final class LecturePlace
{
    /**
     * @param  list<string>  $units
     * @param  list<string>|null  $sections
     */
    private function __construct(public Organization $company, public Organization $unit, public array $units, public ?array $sections) {}

    public static function of(CurrentContext $context): ?self
    {
        $unit = $context->organization();
        $user = $context->user();
        if ($user === null || $unit->type === OrganizationType::Group || $unit->type === OrganizationType::Department) {
            return null;
        }
        $classroom = app(Classroom::class);
        $company = $classroom->companyOf($unit);

        return new self($company, $unit, $classroom->subtreeIds($unit), app(Lectures::class)->visibleSections($company, $unit, $user));
    }
}
