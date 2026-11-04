<?php

namespace Modules\Payroll\Letters;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Letters\Contracts\LetterFieldSource;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Component;
use Modules\Payroll\Models\Salary;
use Modules\Payroll\Models\StructureItem;
use Modules\Payroll\Services\PayCalculator;
use Modules\Payroll\Services\Payrolls;

/**
 * An employee's pay in letters (Letters' LetterFieldSource), all marked
 * salary: the basic, the monthly and yearly gross (basic and earnings of
 * the structure, before deductions), the day the salary is from, and the
 * breakdown as a table. The salary in force on the day the letter is made;
 * nothing where Payroll is off or has no currency yet.
 */
class PayLetterFields implements LetterFieldSource
{
    private const FIELDS = ['basic', 'gross', 'gross_yearly', 'effective_from'];

    public function __construct(private ModuleResolver $modules, private Payrolls $payrolls) {}

    public function fields(Organization $company, string $locale): array
    {
        if (! $this->modules->isEnabled('payroll', $company)) {
            return [];
        }
        $field = fn (string $name, bool $table = false) => [
            'key' => "pay.{$name}",
            'label' => __("payroll::letters.{$name}", [], $locale),
            'salary' => true,
            ...($table ? ['table' => true] : []),
            'sample' => __("payroll::letters.sample_{$name}", [], $locale),
        ];

        return [[
            'group' => 'pay',
            'label' => __('payroll::letters.group', [], $locale),
            'fields' => [...array_map($field, self::FIELDS), $field('breakdown', true)],
        ]];
    }

    public function values(Organization $company, array $employeeIds, string $locale): array
    {
        if ($employeeIds === [] || ! $this->modules->isEnabled('payroll', $company)) {
            return [];
        }
        try {
            $currency = $this->payrolls->currency($company);
        } catch (PayrollException) {
            return [];
        }
        $today = CarbonImmutable::now('UTC')->toDateString();
        $salaries = $this->payrolls->query(Salary::class, $company)->whereIn('employee_id', array_values(array_unique($employeeIds)))
            ->where('effective_from', '<=', $today)->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->orderBy('effective_from')->get()->keyBy('employee_id');
        $items = $this->payrolls->query(StructureItem::class, $company)->whereIn('structure_id', $salaries->pluck('structure_id')->unique()->all())
            ->orderBy('sort')->get()->groupBy('structure_id');
        $components = $this->payrolls->query(Component::class, $company)->get()->keyBy('id');
        $money = fn (int $minor) => MoneyText::format($minor, $currency, $locale);

        $values = [];
        foreach ($salaries as $employeeId => $salary) {
            $basic = $salary->basic_minor;
            $rows = [[__('payroll::letters.basic', [], $locale), $money($basic)]];
            $gross = $basic;
            foreach ($items->get($salary->structure_id, collect()) as $item) {
                $component = $components->get($item->component_id);
                if ($component === null || $component->kind !== 'earning') {
                    continue;
                }
                $amount = $item->calc === 'fixed' ? (int) $item->amount_minor : PayCalculator::share($basic, (int) $item->rate_bp);
                $gross += $amount;
                $rows[] = [$component->textIn('name', $locale), $money($amount)];
            }
            $rows[] = [__('payroll::letters.gross', [], $locale), $money($gross)];
            $values[(string) $employeeId] = [
                'pay.basic' => $money($basic),
                'pay.gross' => $money($gross),
                'pay.gross_yearly' => $money($gross * 12),
                'pay.effective_from' => $salary->effective_from->locale($locale)->isoFormat('LL'),
                'pay.breakdown' => $rows,
            ];
        }

        return $values;
    }
}
