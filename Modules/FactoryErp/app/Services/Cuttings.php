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
use Modules\FactoryErp\Models\Cutting;
use Modules\FactoryErp\Models\CuttingLine;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;

/**
 * The cutting table: each lay cut for a confirmed or running order, pieces
 * per size and colour, numbered 1, 2, 3 within the order. A size and colour
 * is cut at most to what was ordered plus the allowance (rule
 * factory_erp.over_cut_bp, basis points, rounded down). The first lay puts
 * a confirmed order into production. A wrong lay is voided with a reason
 * and stops counting; it is never deleted.
 */
class Cuttings
{
    public function __construct(
        private Floor $floor,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** @return Collection<int, Cutting> */
    public function of(Organization $company, Order $order): Collection
    {
        return $this->floor->query(Cutting::class, $company)->where('order_id', $order->getKey())->orderByDesc('lay_number')->get();
    }

    /**
     * @param  list<string>  $cuttingIds
     * @return Collection<string, Collection<int, CuttingLine>>
     */
    public function lines(Organization $company, array $cuttingIds): Collection
    {
        return $this->floor->query(CuttingLine::class, $company)->whereIn('cutting_id', $cuttingIds)->get()->groupBy('cutting_id');
    }

    /**
     * Pieces cut so far per "size|colour" (voided lays left out).
     *
     * @return array<string, int>
     */
    public function cut(Organization $company, Order $order): array
    {
        return $this->floor->query(CuttingLine::class, $company)->where('fac_cutting_lines.order_id', $order->getKey())
            ->join('fac_cuttings', 'fac_cuttings.id', '=', 'fac_cutting_lines.cutting_id')->whereNull('fac_cuttings.voided_at')
            ->get(['fac_cutting_lines.size', 'fac_cutting_lines.color', 'fac_cutting_lines.quantity'])
            ->groupBy(fn (CuttingLine $line) => "{$line->size}|{$line->color}")->map(fn ($lines) => (int) $lines->sum('quantity'))->all();
    }

    /**
     * @param  array<string, mixed>  $data  Validated by CuttingRequest.
     */
    public function record(Organization $company, Organization $unit, Order $order, array $data, User $actor): Cutting
    {
        if (($data['op_id'] ?? null) !== null) {
            $done = $this->floor->query(Cutting::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        $today = $this->floor->today($company);
        $on = isset($data['cut_on']) ? CarbonImmutable::parse($data['cut_on'], 'UTC') : $today;
        if ($on->greaterThan($today)) {
            throw FactoryException::futureDate('cut_on');
        }
        $cells = [];
        foreach ($data['lines'] as $line) {
            $key = "{$line['size']}|{$line['color']}";
            $cells[$key] = ($cells[$key] ?? 0) + (int) $line['quantity'];
        }
        $cells = array_filter($cells);
        if ($cells === []) {
            throw FactoryException::noPieces();
        }
        $allowanceBp = (int) $this->rules->get('factory_erp.over_cut_bp', $this->contexts->forOrganization($unit));

        return $this->floor->transaction($company, function () use ($company, $unit, $order, $data, $on, $cells, $allowanceBp, $actor) {
            $order = $this->floor->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['confirmed', 'in_production'], true)) {
                throw FactoryException::wrongStatus();
            }
            $ordered = $this->floor->query(OrderLine::class, $company)->where('order_id', $order->getKey())->get()
                ->mapWithKeys(fn (OrderLine $line) => ["{$line->size}|{$line->color}" => $line->quantity])->all();
            $cut = $this->cut($company, $order);
            foreach ($cells as $key => $quantity) {
                $allowed = intdiv(($ordered[$key] ?? 0) * (10000 + $allowanceBp), 10000);
                if (($cut[$key] ?? 0) + $quantity > $allowed) {
                    [$size, $color] = explode('|', $key, 2);

                    throw FactoryException::overCut($size, $color, max(0, $allowed - ($cut[$key] ?? 0)));
                }
            }

            $lay = (int) $this->floor->query(Cutting::class, $company)->where('order_id', $order->getKey())->max('lay_number') + 1;
            $cutting = $this->floor->query(Cutting::class, $company)->create([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'order_id' => $order->getKey(), 'lay_number' => $lay,
                'cut_on' => $on->toDateString(), 'pieces' => array_sum($cells), 'note' => $data['note'] ?? null,
                'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
            ]);
            foreach ($cells as $key => $quantity) {
                [$size, $color] = explode('|', $key, 2);
                $this->floor->query(CuttingLine::class, $company)->create([
                    'organization_id' => $company->getKey(), 'cutting_id' => $cutting->getKey(), 'order_id' => $order->getKey(),
                    'size' => $size, 'color' => $color, 'quantity' => $quantity,
                ]);
            }
            $this->audit->record('factory_erp.cutting_recorded', $cutting, new: ['order' => $order->number, 'lay' => $lay, 'pieces' => $cutting->pieces],
                actor: $actor, organizationId: $company->getKey());

            // Cloth cut: the order is in production.
            if ($order->status === 'confirmed') {
                $order->status = 'in_production';
                $order->version++;
                $order->save();
                $this->audit->record('factory_erp.order_in_production', $order, old: ['status' => 'confirmed'], new: ['status' => 'in_production'],
                    actor: $actor, organizationId: $company->getKey());
            }

            return $cutting;
        });
    }

    /** A wrong lay stops counting; its pieces may be cut again. */
    public function void(Organization $company, Cutting $cutting, int $baseVersion, string $reason, User $actor): Cutting
    {
        return $this->floor->transaction($company, function () use ($company, $cutting, $baseVersion, $reason, $actor) {
            $cutting = $this->floor->query(Cutting::class, $company)->whereKey($cutting->getKey())->lockForUpdate()->firstOrFail();
            if ($cutting->version !== $baseVersion) {
                throw FactoryException::versionConflict(['version' => $cutting->version]);
            }
            $order = $this->floor->query(Order::class, $company)->whereKey($cutting->order_id)->firstOrFail();
            if ($cutting->voided_at !== null || ! in_array($order->status, ['confirmed', 'in_production'], true)) {
                throw FactoryException::wrongStatus();
            }
            $cutting->forceFill(['voided_at' => now(), 'voided_by' => $actor->getKey(), 'void_reason' => $reason, 'version' => $cutting->version + 1])->save();
            $this->audit->record('factory_erp.cutting_voided', $cutting, old: ['pieces' => $cutting->pieces], new: ['voided' => true],
                reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $cutting;
        });
    }
}
