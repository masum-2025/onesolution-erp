<?php

namespace Modules\Payroll\Letters;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Letters\Contracts\LetterSource;
use Modules\Payroll\Models\IncrementLine;
use Modules\Payroll\Models\IncrementRound;
use Modules\Payroll\Services\Payrolls;

/**
 * Increment letters from a salary increment round (Letters' LetterSource):
 * once applied, the people whose salary it raised (lines left out or held
 * back get none). Nothing where Payroll is off or the round is another
 * company's; a round not applied yet is refused with why.
 */
class IncrementLetters implements LetterSource
{
    public const TYPE = 'payroll.increment_round';

    public function __construct(private ModuleResolver $modules, private Payrolls $payrolls) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function people(Organization $company, string $id, string $locale): ?array
    {
        if (! $this->modules->isEnabled('payroll', $company)) {
            return null;
        }
        $round = $this->payrolls->query(IncrementRound::class, $company)->whereKey($id)->first();
        if ($round === null) {
            return null;
        }
        if ($round->status !== IncrementRound::APPLIED) {
            return ['refused' => __('payroll::letters.round_not_applied', [], $locale)];
        }

        return [
            'title' => $round->textIn('title', $locale),
            'kind' => 'increment',
            'employee_ids' => $this->payrolls->query(IncrementLine::class, $company)->where('round_id', $round->getKey())
                ->whereNotNull('applied_salary_id')->orderBy('employee_name')->pluck('employee_id')->map(fn ($employee) => (string) $employee)->all(),
        ];
    }
}
