<?php

namespace Modules\EducationFees\Services;

use Carbon\CarbonImmutable;
use Modules\Accounting\Services\TaxCodes;

/**
 * The arithmetic of fees, with integers only (minor units, basis points,
 * half up) and no database, so it can be tested on its own.
 */
final class FeeMath
{
    public const WHOLE_BP = 10000;

    /** How much a structure's narrowing counts when two fit: class, then category, programme, campus. */
    private const WEIGHTS = ['level_id' => 8, 'category_id' => 4, 'program_id' => 2, 'unit_id' => 1];

    /** A rate per credit times credits kept in hundredths: 250 000 for 15 credits (1 500) -> 3 750 000, half up. */
    public static function perCredits(int $rateMinor, int $creditsCenti): int
    {
        return intdiv($rateMinor * $creditsCenti + 50, 100);
    }

    /** A part of an amount: 1 250 000 at 1 500 bp (15 %) -> 187 500, half up. */
    public static function percentOf(int $amountMinor, int $basisPoints): int
    {
        return intdiv($amountMinor * $basisPoints + intdiv(self::WHOLE_BP, 2), self::WHOLE_BP);
    }

    /**
     * The amount of each head for a student: from the most specific active
     * structure that fits them (its campus, programme, class and category are
     * empty or theirs), head by head.
     *
     * @param  list<array{id: string, unit_id: ?string, program_id: ?string, level_id: ?string, category_id: ?string, lines: array<string, array{amount_minor: int, months: ?array}>}>  $structures
     * @param  array{unit_id: ?string, program_id: ?string, level_id: ?string, category_id: ?string}  $student  Where they study (unit and class from the enrollment).
     * @return array<string, array{amount_minor: int, months: ?array, structure_id: string}> By head id.
     */
    public static function amountsFor(array $structures, array $student): array
    {
        $chosen = [];
        foreach ($structures as $structure) {
            $score = 0;
            foreach (self::WEIGHTS as $field => $weight) {
                if ($structure[$field] === null) {
                    continue;
                }
                if ($structure[$field] !== ($student[$field] ?? null)) {
                    continue 2;
                }
                $score += $weight;
            }
            foreach ($structure['lines'] as $headId => $line) {
                if (! isset($chosen[$headId]) || $score > $chosen[$headId]['score']) {
                    $chosen[$headId] = ['score' => $score, 'amount_minor' => $line['amount_minor'], 'months' => $line['months'] ?: null, 'structure_id' => $structure['id']];
                }
            }
        }

        return array_map(fn (array $line) => ['amount_minor' => $line['amount_minor'], 'months' => $line['months'], 'structure_id' => $line['structure_id']], $chosen);
    }

    /**
     * A bill line: the discount (percent concessions and the sibling
     * discount add up, then fixed ones; never more than the amount), the tax
     * of a head with a tax code (inside the fee, or on top), and what is owed.
     *
     * @return array{discount_minor: int, tax_minor: int, due_minor: int}
     */
    public static function line(int $amountMinor, int $percentBp, int $fixedMinor, int $taxRateBp = 0, bool $feesIncludeTax = true): array
    {
        $discount = min($amountMinor, self::percentOf($amountMinor, min(self::WHOLE_BP, $percentBp)) + $fixedMinor);
        $charged = $amountMinor - $discount;
        $split = TaxCodes::split($charged, $taxRateBp, $feesIncludeTax);

        return ['discount_minor' => $discount, 'tax_minor' => $split['tax'], 'due_minor' => $split['net'] + $split['tax']];
    }

    /**
     * The late fine a bill should carry by today under the rule
     * {enabled, mode: once|per_day|per_month, amount_minor, grace_days, max_minor}:
     * nothing until the grace days after the due date are over; then once,
     * per day late or per month (30 days) started; never above the most (0: no most).
     *
     * @param  array<string, mixed>  $rule
     */
    public static function fineBy(array $rule, string $dueDate, string $today): int
    {
        if (! ($rule['enabled'] ?? false) || ($rule['amount_minor'] ?? 0) <= 0) {
            return 0;
        }
        $start = CarbonImmutable::parse($dueDate, 'UTC')->addDays((int) ($rule['grace_days'] ?? 0));
        $late = (int) $start->diffInDays(CarbonImmutable::parse($today, 'UTC'), false);
        if ($late < 1) {
            return 0;
        }
        $times = match ($rule['mode'] ?? 'once') {
            'per_day' => $late,
            'per_month' => intdiv($late - 1, 30) + 1,
            default => 1,
        };
        $fine = $times * (int) $rule['amount_minor'];
        $most = (int) ($rule['max_minor'] ?? 0);

        return $most > 0 ? min($fine, $most) : $fine;
    }

    /** The day a month's bill is due: the rule's day, or the month's last day when shorter. */
    public static function dueInMonth(string $period, int $day): string
    {
        $month = CarbonImmutable::parse($period.'-01', 'UTC');

        return $month->setDay(min($day, $month->daysInMonth))->toDateString();
    }
}
