<?php

namespace Modules\Payroll\Letters;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Letters\Contracts\LetterFieldSource;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Component;
use Modules\Payroll\Models\IncrementLine;
use Modules\Payroll\Models\IncrementRound;
use Modules\Payroll\Models\Salary;
use Modules\Payroll\Models\StructureItem;
use Modules\Payroll\Services\PayCalculator;
use Modules\Payroll\Services\Payrolls;
use NumberFormatter;

/**
 * An employee's pay in letters (Letters' LetterFieldSource), all marked
 * salary: the basic, the monthly and yearly gross (basic and earnings of
 * the structure, before deductions), the day the salary is from, and the
 * breakdown as a table. The salary in force on the day the letter is made;
 * nothing where Payroll is off or has no currency yet. Issued from an
 * applied increment round (context IncrementLetters::TYPE): the salary in
 * force from the round's day, and the increment's fields (basic before and
 * after, the raise, the rate, the day, the round) for its people.
 */
class PayLetterFields implements LetterFieldSource
{
    private const FIELDS = ['basic', 'gross', 'gross_yearly', 'effective_from'];

    private const INCREMENT_FIELDS = ['previous_basic', 'new_basic', 'raise', 'rate', 'effective_from', 'round', 'arrears'];

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

        $increment = fn (string $name) => [
            'key' => "increment.{$name}",
            'label' => __("payroll::letters.increment_{$name}", [], $locale),
            'salary' => true,
            'sample' => __("payroll::letters.sample_increment_{$name}", [], $locale),
        ];

        return [[
            'group' => 'pay',
            'label' => __('payroll::letters.group', [], $locale),
            'fields' => [...array_map($field, self::FIELDS), $field('breakdown', true)],
        ], [
            // Filled only when letters are issued from an applied increment round.
            'group' => 'increment',
            'label' => __('payroll::letters.group_increment', [], $locale),
            'fields' => array_map($increment, self::INCREMENT_FIELDS),
        ]];
    }

    public function values(Organization $company, array $employeeIds, string $locale, array $context = []): array
    {
        if ($employeeIds === [] || ! $this->modules->isEnabled('payroll', $company)) {
            return [];
        }
        try {
            $currency = $this->payrolls->currency($company);
        } catch (PayrollException) {
            return [];
        }
        // From an applied increment round: its fields, and the pay in force from its day.
        $round = ($context['type'] ?? null) === IncrementLetters::TYPE
            ? $this->payrolls->query(IncrementRound::class, $company)->whereKey((string) ($context['id'] ?? ''))->where('status', IncrementRound::APPLIED)->first()
            : null;
        $day = ($round?->effective_from ?? CarbonImmutable::now('UTC'))->toDateString();
        $salaries = $this->payrolls->query(Salary::class, $company)->whereIn('employee_id', array_values(array_unique($employeeIds)))
            ->where('effective_from', '<=', $day)->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
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
        if ($round !== null) {
            $lines = $this->payrolls->query(IncrementLine::class, $company)->where('round_id', $round->getKey())
                ->whereIn('employee_id', array_values(array_unique($employeeIds)))->whereNotNull('applied_salary_id')->get();
            foreach ($lines as $line) {
                $values[(string) $line->employee_id] = [...($values[(string) $line->employee_id] ?? []),
                    'increment.round' => $round->textIn('title', $locale),
                    'increment.previous_basic' => MoneyText::format($line->current_minor, $round->currency_code, $locale),
                    'increment.new_basic' => MoneyText::format($line->new_minor, $round->currency_code, $locale),
                    'increment.raise' => MoneyText::format($line->new_minor - $line->current_minor, $round->currency_code, $locale),
                    'increment.rate' => self::percent($line->rate_bp, $locale),
                    'increment.effective_from' => $round->effective_from->locale($locale)->isoFormat('LL'),
                    // Arrears for months already paid (planned or paid with a later run); none is shown as zero.
                    'increment.arrears' => MoneyText::format($line->arrears_minor, $round->currency_code, $locale),
                ];
            }
        }

        return $values;
    }

    /** Basis points as a percent in the language's digits, without floats ("10%", "৭.৫%"). */
    private static function percent(int $basisPoints, string $locale): string
    {
        $numbers = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $fraction = rtrim(str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT), '0');
        $digits = fn (string $text) => implode('', array_map(fn (string $digit) => $numbers->format((int) $digit), str_split($text)));

        return $numbers->format(intdiv($basisPoints, 100))
            .($fraction === '' ? '' : $numbers->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL).$digits($fraction)).'%';
    }
}
