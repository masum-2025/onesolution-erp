<?php

namespace Modules\FactoryErp\Services;

use App\Models\User;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\Line;
use Modules\FactoryErp\Models\LineDay;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Output;

/**
 * How well a sewing line works on a day, integers only:
 *
 *     earned minutes    = good pieces × the style's standard minutes (SMV)
 *     available minutes = people × minutes worked
 *     efficiency        = earned ÷ available
 *     target an hour    = people × 60 ÷ SMV × target efficiency
 *
 * People are operators, and helpers too where the company counts them
 * (rule factory_erp.efficiency_counts_helpers); a day's minutes default to
 * factory_erp.shift_minutes. A line's day (who worked, how long) is
 * written by its supervisor and changed with its version.
 */
class Efficiency
{
    public function __construct(
        private Floor $floor,
        private Styles $styles,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @param  list<string>  $units
     * @return Collection<string, LineDay> keyed by line
     */
    public function days(Organization $company, array $units, CarbonImmutable $day): Collection
    {
        return $this->floor->query(LineDay::class, $company)->whereIn('unit_id', $units)->where('worked_on', $day->toDateString())->get()->keyBy('line_id');
    }

    /**
     * Write (or change, with base_version) who worked on a line on a day.
     *
     * @param  array<string, mixed>  $data  Validated by LineDayRequest.
     */
    public function saveDay(Organization $company, Organization $unit, array $data, User $actor): LineDay
    {
        $line = $this->floor->query(Line::class, $company)->whereKey($data['line_id'])->whereIn('unit_id', $this->floor->subtreeIds($unit))->first();
        if ($line === null || ! $line->is_active) {
            throw FactoryException::lineOff();
        }
        $today = $this->floor->today($company);
        $on = isset($data['worked_on']) ? CarbonImmutable::parse($data['worked_on'], 'UTC') : $today;
        if ($on->greaterThan($today)) {
            throw FactoryException::futureDate('worked_on');
        }
        $minutes = (int) ($data['minutes'] ?? $this->rules->get('factory_erp.shift_minutes', $this->contexts->forOrganization($unit)));

        return $this->floor->transaction($company, function () use ($company, $line, $data, $on, $minutes, $actor) {
            $day = $this->floor->query(LineDay::class, $company)->where('line_id', $line->getKey())->where('worked_on', $on->toDateString())->lockForUpdate()->first();
            if ($day !== null && $day->version !== (int) ($data['base_version'] ?? 0)) {
                throw FactoryException::versionConflict(['version' => $day->version]);
            }
            $day ??= new LineDay(['organization_id' => $company->getKey(), 'unit_id' => $line->unit_id, 'line_id' => $line->getKey(), 'worked_on' => $on->toDateString(), 'version' => 0]);
            $day->fill(['operators' => (int) $data['operators'], 'helpers' => (int) ($data['helpers'] ?? 0), 'minutes' => $minutes, 'note' => $data['note'] ?? null, 'updated_by' => $actor->getKey()]);
            $day->version++;
            $day->save();

            return $day;
        });
    }

    /**
     * Each line's day at the unit: good pieces, earned and available minutes, efficiency (basis points; null without a day written).
     *
     * @return array<string, array{good: int, earned_milli: int, available: int, efficiency_bp: int|null, people: int}>
     */
    public function ofDay(Organization $company, Organization $unit, CarbonImmutable $day): array
    {
        $units = $this->floor->subtreeIds($unit);
        $helpers = (bool) $this->rules->get('factory_erp.efficiency_counts_helpers', $this->contexts->forOrganization($unit));
        $outputs = $this->floor->query(Output::class, $company)->whereIn('unit_id', $units)->where('worked_on', $day->toDateString())->whereNull('voided_at')->get(['line_id', 'order_id', 'good']);
        $styles = $this->floor->query(Order::class, $company)->whereKey($outputs->pluck('order_id')->unique())->pluck('style_id', 'id');
        $smv = $this->styles->smv($company, $styles->unique()->values()->all());
        $days = $this->days($company, $units, $day);

        $lines = [];
        foreach ($outputs as $output) {
            $lines[$output->line_id] ??= ['good' => 0, 'earned_milli' => 0];
            $lines[$output->line_id]['good'] += $output->good;
            $lines[$output->line_id]['earned_milli'] += $output->good * ($smv[$styles[$output->order_id] ?? ''] ?? 0);
        }
        foreach ($days->keys() as $lineId) {
            $lines[$lineId] ??= ['good' => 0, 'earned_milli' => 0];
        }

        return collect($lines)->map(function (array $line, string $lineId) use ($days, $helpers) {
            $people = isset($days[$lineId]) ? self::people($days[$lineId]->operators, $days[$lineId]->helpers, $helpers) : 0;
            $available = isset($days[$lineId]) ? $people * $days[$lineId]->minutes : 0;

            return [...$line, 'available' => $available, 'people' => $people, 'efficiency_bp' => self::efficiencyBp($line['earned_milli'], $available)];
        })->all();
    }

    public static function people(int $operators, int $helpers, bool $countHelpers): int
    {
        return $operators + ($countHelpers ? $helpers : 0);
    }

    /** Earned (thousandths of a minute) over available minutes, basis points rounded down; null when nothing was available. */
    public static function efficiencyBp(int $earnedMilli, int $availableMinutes): ?int
    {
        return $availableMinutes > 0 ? intdiv($earnedMilli * 10, $availableMinutes) : null;
    }

    /** Pieces an hour at the target: people × 60 minutes ÷ SMV × target, rounded down. */
    public static function hourTarget(int $people, int $smvMilli, int $targetBp): int
    {
        return $smvMilli > 0 ? intdiv($people * 60 * 1000 * $targetBp, $smvMilli * 10000) : 0;
    }
}
