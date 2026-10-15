<?php

namespace Modules\EducationFees\Setup;

use App\Platform\Tenancy\Models\Organization;
use Modules\Education\Contracts\SetupStepProvider;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Models\FeeRun;
use Modules\EducationFees\Models\FeeStructure;
use Modules\EducationFees\Services\FeeOffice;

/**
 * Fees on the setup checklist: the heads (tuition, admission…), an active
 * fee structure for the open session, then the first bills (a help, not a need).
 */
class FeeSetupSteps implements SetupStepProvider
{
    public function __construct(private FeeOffice $office) {}

    public function module(): string
    {
        return 'education_fees';
    }

    public function steps(Organization $company, ?string $sessionId, array $unitIds): array
    {
        $heads = $this->office->query(FeeHead::class, $company)->where('is_active', true)->count();
        $structures = $sessionId === null ? 0 : $this->office->query(FeeStructure::class, $company)->where('session_id', $sessionId)->where('status', 'active')->count();
        $runs = $sessionId === null ? 0 : $this->office->query(FeeRun::class, $company)->where('session_id', $sessionId)->whereIn('unit_id', $unitIds)->where('status', 'final')->count();
        $text = fn (string $key) => __("education_fees::setup.steps.{$key}");

        return [
            ['key' => 'fee_heads', 'label' => $text('heads.label'), 'route' => '/fees/heads', 'done' => $heads > 0, 'detail' => $heads > 0 ? null : $text('heads.missing')],
            ['key' => 'fee_structure', 'label' => $text('structure.label'), 'route' => '/fees/structures', 'done' => $structures > 0, 'detail' => $structures > 0 ? null : $text('structure.missing')],
            ['key' => 'fee_bills', 'label' => $text('bills.label'), 'route' => '/fees/runs', 'optional' => true, 'done' => $runs > 0, 'detail' => $runs > 0 ? null : $text('bills.missing')],
        ];
    }
}
