<?php

namespace Modules\EducationFees\Directory;

use App\Platform\Tenancy\Models\Organization;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Services\FeeOffice;

/**
 * What other modules may ask Fees (they never read fee tables): how much
 * students owe on their unpaid bills, in minor units. Exams asks it to hold
 * results of students who owe (rule education_exams.withhold_results_with_dues).
 */
class FeeDirectory
{
    public function __construct(private FeeOffice $office) {}

    /**
     * Students who owe something now, with what they owe (students who owe nothing are left out).
     *
     * @param  list<string>  $studentIds
     * @return array<string, int>
     */
    public function owing(Organization $company, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }
        $owed = [];
        $bills = $this->office->query(Bill::class, $company)->whereIn('student_id', array_values(array_unique($studentIds)))->where('status', 'open')->get();
        foreach ($bills as $bill) {
            $balance = $bill->balanceMinor();
            if ($balance > 0) {
                $owed[$bill->student_id] = ($owed[$bill->student_id] ?? 0) + $balance;
            }
        }

        return $owed;
    }
}
