<?php

namespace Modules\EducationExams\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Modules\EducationExams\Exceptions\ExamException;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Services\ExamOffice;

/**
 * The unit in the address (visible to the context), its institution, and
 * records only of that unit and the campuses below it; anything else is the
 * same 404 (another institution's or another campus's record looks unknown).
 */
trait FindsExamUnit
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization}
     */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(ExamOffice::class)->companyOf($unit)];
    }

    /** @return list<string> */
    protected function unitIds(Organization $unit): array
    {
        return app(ExamOffice::class)->subtreeIds($unit);
    }

    /**
     * Where exams seen from a unit are set: the unit and below, and the
     * units above it (an exam of the institution covers every campus).
     *
     * @return list<string>
     */
    protected function examUnitIds(Organization $unit): array
    {
        return array_values(array_unique([...$this->unitIds($unit), ...array_map('strval', $unit->ancestorIds())]));
    }

    /**
     * An exam to read from a unit: set there, below, or above it. Changing
     * an exam still needs recordIn (set at the unit or below).
     */
    protected function examIn(Organization $unit, Organization $company, string $id): Exam
    {
        $exam = app(ExamOffice::class)->query(Exam::class, $company)->whereKey($id)->first();
        if ($exam === null || ! in_array($exam->unit_id, $this->examUnitIds($unit), true)) {
            throw ExamException::notFound('exam');
        }

        return $exam;
    }

    /**
     * A record of the institution at the unit or a campus below it.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function recordIn(string $class, Organization $unit, Organization $company, string $id, string $what): Model
    {
        $record = app(ExamOffice::class)->query($class, $company)->whereKey($id)->first();
        if ($record === null || ! in_array($record->unit_id, $this->unitIds($unit), true)) {
            throw ExamException::notFound($what);
        }

        return $record;
    }
}
