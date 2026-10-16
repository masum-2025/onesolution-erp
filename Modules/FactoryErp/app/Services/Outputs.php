<?php

namespace Modules\FactoryErp\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\Line;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Output;
use Modules\FactoryErp\Models\OutputLine;

/**
 * What sewing lines make: for an order in production, good pieces and
 * rejects per size and colour, by the hour (or by the day: rule
 * factory_erp.output_by). Good and rejects of a size and colour never go
 * above what was cut of it. Written from a phone on the floor, offline
 * too: the op id makes an entry count once. A wrong entry is voided with a
 * reason; it stays in the list and stops counting.
 */
class Outputs
{
    public function __construct(
        private Floor $floor,
        private Progress $progress,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * Entries of a day at the unit and the units below it, newest first.
     *
     * @return Collection<int, Output>
     */
    public function ofDay(Organization $company, Organization $unit, CarbonImmutable $day, ?string $lineId = null): Collection
    {
        return $this->floor->query(Output::class, $company)->whereIn('unit_id', $this->floor->subtreeIds($unit))->where('worked_on', $day->toDateString())
            ->when($lineId, fn ($query) => $query->where('line_id', $lineId))->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /**
     * @param  list<string>  $outputIds
     * @return Collection<string, Collection<int, OutputLine>>
     */
    public function lines(Organization $company, array $outputIds): Collection
    {
        return $this->floor->query(OutputLine::class, $company)->whereIn('output_id', $outputIds)->get()->groupBy('output_id');
    }

    /**
     * @param  array<string, mixed>  $data  line_id, order_id, worked_on?, hour?, lines, op_id? (checked by OutputRequest or the offline sync).
     */
    public function record(Organization $company, Organization $unit, array $data, User $actor): Output
    {
        if (($data['op_id'] ?? null) !== null) {
            $done = $this->floor->query(Output::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        $units = $this->floor->subtreeIds($unit);
        $line = $this->floor->query(Line::class, $company)->whereKey($data['line_id'])->whereIn('unit_id', $units)->first();
        if ($line === null || ! $line->is_active) {
            throw FactoryException::lineOff();
        }
        $order = $this->floor->query(Order::class, $company)->whereKey($data['order_id'])->whereIn('unit_id', $units)->first()
            ?? throw FactoryException::notFound('order');
        $byHour = (string) $this->rules->get('factory_erp.output_by', $this->contexts->forOrganization($unit)) === 'hour';
        $hour = $data['hour'] ?? null;
        if ($byHour !== ($hour !== null)) {
            throw FactoryException::hourRule($byHour);
        }
        $today = $this->floor->today($company);
        $on = isset($data['worked_on']) ? CarbonImmutable::parse($data['worked_on'], 'UTC') : $today;
        if ($on->greaterThan($today)) {
            throw FactoryException::futureDate('worked_on');
        }
        $cells = [];
        foreach ($data['lines'] as $cell) {
            $key = "{$cell['size']}|{$cell['color']}";
            $cells[$key] ??= ['good' => 0, 'rejects' => 0];
            $cells[$key]['good'] += (int) ($cell['good'] ?? 0);
            $cells[$key]['rejects'] += (int) ($cell['rejects'] ?? 0);
        }
        $cells = array_filter($cells, fn (array $cell) => $cell['good'] + $cell['rejects'] > 0);
        if ($cells === []) {
            throw FactoryException::noPieces();
        }

        return $this->floor->transaction($company, function () use ($company, $line, $order, $data, $on, $hour, $cells, $actor) {
            // One entry at a time per order: what is sewn is read and added together.
            $order = $this->floor->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status !== 'in_production') {
                throw FactoryException::wrongStatus();
            }
            $progress = $this->progress->of($company, $order);
            foreach ($cells as $key => $cell) {
                $at = $progress[$key] ?? ['cut' => 0, 'good' => 0, 'rejects' => 0];
                $left = $at['cut'] - $at['good'] - $at['rejects'];
                if ($left < $cell['good'] + $cell['rejects']) {
                    [$size, $color] = explode('|', $key, 2);

                    throw FactoryException::overStage('sewn', $size, $color, $left);
                }
            }
            $output = $this->floor->query(Output::class, $company)->create([
                'organization_id' => $company->getKey(), 'unit_id' => $line->unit_id, 'line_id' => $line->getKey(), 'order_id' => $order->getKey(),
                'worked_on' => $on->toDateString(), 'hour' => $hour, 'good' => array_sum(array_column($cells, 'good')), 'rejects' => array_sum(array_column($cells, 'rejects')),
                'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
            ]);
            foreach ($cells as $key => $cell) {
                [$size, $color] = explode('|', $key, 2);
                $this->floor->query(OutputLine::class, $company)->create([
                    'organization_id' => $company->getKey(), 'output_id' => $output->getKey(), 'order_id' => $order->getKey(),
                    'size' => $size, 'color' => $color, 'good' => $cell['good'], 'rejects' => $cell['rejects'],
                ]);
            }

            return $output;
        });
    }

    /** A wrong entry stops counting; its pieces may be written again. */
    public function void(Organization $company, Output $output, int $baseVersion, string $reason, User $actor): Output
    {
        return $this->floor->transaction($company, function () use ($company, $output, $baseVersion, $reason, $actor) {
            $output = $this->floor->query(Output::class, $company)->whereKey($output->getKey())->lockForUpdate()->firstOrFail();
            if ($output->version !== $baseVersion) {
                throw FactoryException::versionConflict(['version' => $output->version]);
            }
            $order = $this->floor->query(Order::class, $company)->whereKey($output->order_id)->lockForUpdate()->firstOrFail();
            if ($output->voided_at !== null || $order->status !== 'in_production') {
                throw FactoryException::wrongStatus();
            }
            // What went into the store stays covered by good pieces.
            $progress = $this->progress->of($company, $order);
            foreach ($this->lines($company, [$output->getKey()])[$output->getKey()] ?? [] as $line) {
                $at = $progress["{$line->size}|{$line->color}"];
                if ($at['received'] > $at['good'] - $line->good) {
                    throw FactoryException::overStage('received', $line->size, $line->color, $at['good'] - $at['received']);
                }
            }
            $output->forceFill(['voided_at' => now(), 'voided_by' => $actor->getKey(), 'void_reason' => $reason, 'version' => $output->version + 1])->save();
            $this->audit->record('factory_erp.output_voided', $output, old: ['good' => $output->good, 'rejects' => $output->rejects], new: ['voided' => true],
                reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $output;
        });
    }
}
