<?php

namespace Modules\EducationExams\Dashboard;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\EducationExams\Services\ExamOffice;

/**
 * Where the exam widgets look from the context: the institution, the units
 * of the context, where its exams may be set (also the units above: an
 * institution's exam covers every campus), and whether the person sees
 * every sheet (who manages or moderates) or only the ones they mark.
 * A group or department has no exams of its own.
 */
final class ExamPlace
{
    /**
     * @param  list<string>  $units
     * @param  list<string>  $examUnits
     */
    private function __construct(public Organization $company, public Organization $unit, public array $units, public array $examUnits, public bool $seesAll) {}

    public static function of(CurrentContext $context): ?self
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group || $unit->type === OrganizationType::Department) {
            return null;
        }
        $office = app(ExamOffice::class);
        $units = $office->subtreeIds($unit);

        return new self(
            $office->companyOf($unit),
            $unit,
            $units,
            array_values(array_unique([...$units, ...array_map('strval', $unit->ancestorIds())])),
            Gate::allows('education_exams.manage', $unit) || Gate::allows('education_exams.moderate', $unit),
        );
    }

    /** A data label in the reader's language (the fallback language, then any). */
    public static function text(array $texts): string
    {
        return (string) ($texts[app()->getLocale()] ?? $texts[config('app.fallback_locale')] ?? reset($texts) ?: '');
    }
}
