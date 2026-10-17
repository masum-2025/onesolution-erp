<?php

namespace Modules\FactoryErp\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Services\Organizations;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\LcOrder;
use Modules\FactoryErp\Models\LetterOfCredit;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Shipment;

/**
 * Buyers' letters of credit: the amount and currency with a tolerance,
 * the last day to ship and the expiry, partial shipments, ports, payment
 * days and the documents the bank wants; the orders each pays for (the
 * buyer's, in its currency, one L/C per order). What it has paid for so
 * far comes from its shipped shipments.
 *
 * A shipment under an L/C that does not keep its terms (closed, expired,
 * shipped after the last day, above the amount with the tolerance, or a
 * part shipment where parts are not allowed) has discrepancies: refused
 * where the company is strict (rule factory_erp.lc_strict), else shipped
 * only when the person says so; banks often accept them for a fee.
 */
class LettersOfCredit
{
    public function __construct(private Floor $floor, private Organizations $buyers, private AuditLogger $audit) {}

    /** @return Collection<int, LetterOfCredit> */
    public function of(Organization $company, Organization $unit, ?string $status = null): Collection
    {
        return $this->floor->query(LetterOfCredit::class, $company)->whereIn('unit_id', $this->floor->subtreeIds($unit))
            ->when($status, fn ($query) => $query->where('status', $status))->orderBy('expires_on')->get();
    }

    /** @return list<string> */
    public function orderIds(Organization $company, LetterOfCredit $lc): array
    {
        return $this->floor->query(LcOrder::class, $company)->where('lc_id', $lc->getKey())->pluck('order_id')->all();
    }

    /** The L/C an order is paid under, if any. */
    public function forOrder(Organization $company, Order $order): ?LetterOfCredit
    {
        $id = $this->floor->query(LcOrder::class, $company)->where('order_id', $order->getKey())->value('lc_id');

        return $id === null ? null : $this->floor->query(LetterOfCredit::class, $company)->find($id);
    }

    /**
     * What it has paid for (shipped), what drafts would add, and the ceiling (amount with tolerance), minor units.
     *
     * @return array{shipped: int, drafts: int, ceiling: int, left: int}
     */
    public function usage(Organization $company, LetterOfCredit $lc): array
    {
        $shipments = $this->floor->query(Shipment::class, $company)->where('lc_id', $lc->getKey())->get(['status', 'value_minor']);
        $shipped = (int) $shipments->where('status', 'shipped')->sum('value_minor');
        $drafts = (int) $shipments->where('status', 'draft')->sum('value_minor');

        return ['shipped' => $shipped, 'drafts' => $drafts, 'ceiling' => $lc->ceiling(), 'left' => $lc->ceiling() - $shipped];
    }

    /**
     * @param  array<string, mixed>  $data  Validated by LetterOfCreditRequest.
     */
    public function save(Organization $company, Organization $unit, ?LetterOfCredit $lc, array $data, User $actor): LetterOfCredit
    {
        $buyerId = $lc?->buyer_id ?? $data['buyer_id'];
        if ($lc === null && ! $this->buyers->has($company, $buyerId, Styles::BUYER_TAG)) {
            throw FactoryException::notABuyer();
        }
        $units = $this->floor->subtreeIds($unit);

        return $this->floor->transaction($company, function () use ($company, $unit, $lc, $data, $buyerId, $units, $actor) {
            if ($lc !== null) {
                $lc = $this->floor->query(LetterOfCredit::class, $company)->whereKey($lc->getKey())->lockForUpdate()->firstOrFail();
                if ($lc->version !== (int) $data['base_version']) {
                    throw FactoryException::versionConflict(['version' => $lc->version]);
                }
                $old = $this->terms($lc);
                $lc->fill(array_intersect_key($data, array_flip(self::FIELDS)));
                $lc->version++;
            } else {
                $old = null;
                $lc = new LetterOfCredit(['organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'buyer_id' => $buyerId, 'created_by' => $actor->getKey(),
                    ...array_intersect_key($data, array_flip(self::FIELDS))]);
            }
            $lc->number = trim($lc->number);
            if ($lc->latest_ship_on->greaterThan($lc->expires_on) || $lc->issued_on->greaterThan($lc->latest_ship_on)) {
                throw ValidationException::withMessages(['latest_ship_on' => __('factory_erp::factory.validation.lc_dates')]);
            }
            if ($this->floor->query(LetterOfCredit::class, $company)->where('number', $lc->number)->when($lc->exists, fn ($query) => $query->whereKeyNot($lc->getKey()))->exists()) {
                throw ValidationException::withMessages(['number' => __('factory_erp::factory.validation.code_taken')]);
            }
            $lc->save();

            if (array_key_exists('order_ids', $data)) {
                $orders = $this->floor->query(Order::class, $company)->whereKey($data['order_ids'])->whereIn('unit_id', $units)->get();
                foreach ($data['order_ids'] as $id) {
                    $order = $orders->firstWhere('id', $id) ?? throw FactoryException::notFound('order');
                    if ($order->buyer_id !== $lc->buyer_id || $order->currency !== $lc->currency) {
                        throw FactoryException::lcNotForOrder($order->number);
                    }
                    $elsewhere = $this->floor->query(LcOrder::class, $company)->where('order_id', $id)->where('lc_id', '!=', $lc->getKey())->exists();
                    if ($elsewhere) {
                        throw FactoryException::lcNotForOrder($order->number);
                    }
                }
                $this->floor->query(LcOrder::class, $company)->where('lc_id', $lc->getKey())->whereNotIn('order_id', $data['order_ids'])->delete();
                foreach (array_diff($data['order_ids'], $this->orderIds($company, $lc)) as $id) {
                    $this->floor->query(LcOrder::class, $company)->create(['organization_id' => $company->getKey(), 'lc_id' => $lc->getKey(), 'order_id' => $id]);
                }
            }
            $this->audit->record($old === null ? 'factory_erp.lc_created' : 'factory_erp.lc_updated', $lc, old: $old ?? [],
                new: [...$this->terms($lc), 'orders' => count($this->orderIds($company, $lc))], actor: $actor, organizationId: $company->getKey());

            return $lc;
        });
    }

    /**
     * What a shipment under the L/C would break: codes, empty when it keeps the terms.
     *
     * @return list<string>
     */
    public function discrepancies(Organization $company, LetterOfCredit $lc, Shipment $shipment, CarbonImmutable $today): array
    {
        $found = [];
        if ($lc->status !== 'open') {
            $found[] = 'lc_closed';
        }
        if ($today->greaterThan($lc->expires_on)) {
            $found[] = 'lc_expired';
        }
        if ($shipment->ship_on->greaterThan($lc->latest_ship_on)) {
            $found[] = 'lc_late';
        }
        if ($this->usage($company, $lc)['shipped'] + $shipment->value_minor > $lc->ceiling()) {
            $found[] = 'lc_over';
        }
        if (! $lc->partial_allowed && ! $shipment->is_last) {
            $found[] = 'lc_partial';
        }

        return $found;
    }

    /**
     * Open L/Cs at the units that expire within the days (not expired yet).
     *
     * @param  list<string>  $units
     */
    public function expiringCount(Organization $company, array $units, int $days): int
    {
        $today = $this->floor->today($company);

        return $this->floor->query(LetterOfCredit::class, $company)->whereIn('unit_id', $units)->where('status', 'open')
            ->whereBetween('expires_on', [$today->toDateString(), $today->addDays($days)->toDateString()])->count();
    }

    private const FIELDS = ['number', 'issued_on', 'issuing_bank', 'advising_bank', 'currency', 'amount_minor', 'tolerance_bp', 'latest_ship_on', 'expires_on',
        'partial_allowed', 'transshipment_allowed', 'port_loading', 'port_discharge', 'payment_days', 'documents', 'status', 'note'];

    /** @return array<string, mixed> */
    private function terms(LetterOfCredit $lc): array
    {
        return [
            ...$lc->only(['number', 'currency', 'amount_minor', 'tolerance_bp', 'partial_allowed', 'payment_days', 'status']),
            'latest_ship_on' => $lc->latest_ship_on?->toDateString(), 'expires_on' => $lc->expires_on?->toDateString(),
        ];
    }
}
