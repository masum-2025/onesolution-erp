<?php

namespace Modules\FactoryErp\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Receipt;
use Modules\FactoryErp\Models\ReceiptLine;
use Modules\FactoryErp\Models\Style;
use Modules\Inventory\Services\Stock;

/**
 * Finished pieces of an order in production into a store, as the style's
 * finished item (sizes and colours stay here), at most the good pieces
 * sewn. Their cost comes out of the order's work in progress (materials
 * on the floor less what came in before), shared by the pieces still to
 * come, integers only:
 *
 *     cost = work in progress × pieces ÷ (cut − rejects − received before)
 *
 * The last receipt (or one taking every piece still to come) takes all
 * that is left, so nothing stays in progress. Posts stock up, work in
 * progress down. Append-only.
 */
class Receipts
{
    public function __construct(
        private Floor $floor,
        private Progress $progress,
        private Requirements $requirements,
        private Stock $stock,
        private FactoryPostings $postings,
        private AuditLogger $audit,
    ) {}

    /** @return Collection<int, Receipt> */
    public function of(Organization $company, Order $order): Collection
    {
        return $this->floor->query(Receipt::class, $company)->where('order_id', $order->getKey())->orderByDesc('received_on')->orderByDesc('id')->get();
    }

    /**
     * @param  list<string>  $receiptIds
     * @return Collection<string, Collection<int, ReceiptLine>>
     */
    public function lines(Organization $company, array $receiptIds): Collection
    {
        return $this->floor->query(ReceiptLine::class, $company)->whereIn('receipt_id', $receiptIds)->get()->groupBy('receipt_id');
    }

    /** What the order's work in progress holds now (minor units). */
    public function workInProgress(Organization $company, Order $order): int
    {
        $materials = array_sum(array_column($this->requirements->issued($company, $order), 'cost'));

        return $materials - (int) $this->floor->query(Receipt::class, $company)->where('order_id', $order->getKey())->sum('cost_minor');
    }

    /**
     * @param  array<string, mixed>  $data  Validated by ReceiptRequest.
     */
    public function record(Organization $company, Organization $unit, Order $order, array $data, User $actor): Receipt
    {
        if (($data['op_id'] ?? null) !== null) {
            $done = $this->floor->query(Receipt::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        $warehouse = $this->stock->warehouse($company, $data['warehouse_id']);
        if ($warehouse === null || ! $warehouse['is_active']) {
            throw FactoryException::notFound('warehouse');
        }
        $style = $this->floor->query(Style::class, $company)->whereKey($order->style_id)->firstOrFail();
        if ($style->finished_item_id === null) {
            throw FactoryException::noFinishedItem();
        }
        $today = $this->floor->today($company);
        $on = isset($data['received_on']) ? CarbonImmutable::parse($data['received_on'], 'UTC') : $today;
        if ($on->greaterThan($today)) {
            throw FactoryException::futureDate('received_on');
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
        $currency = $this->floor->currency($company);

        return $this->floor->transaction($company, function () use ($company, $unit, $order, $data, $style, $warehouse, $on, $cells, $currency, $actor) {
            $order = $this->floor->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status !== 'in_production') {
                throw FactoryException::wrongStatus();
            }
            $progress = $this->progress->of($company, $order);
            foreach ($cells as $key => $pieces) {
                $at = $progress[$key] ?? ['good' => 0, 'received' => 0];
                if ($pieces > $at['good'] - $at['received']) {
                    [$size, $color] = explode('|', $key, 2);

                    throw FactoryException::overStage('received', $size, $color, $at['good'] - $at['received']);
                }
            }
            $pieces = array_sum($cells);
            $totals = Progress::totals($progress);
            $toCome = $totals['cut'] - $totals['rejects'] - $totals['received'];
            $wip = max(0, $this->workInProgress($company, $order));
            $last = ! empty($data['is_last']) || $pieces >= $toCome;
            $cost = $last ? $wip : intdiv($wip * $pieces, $toCome);

            $receipt = $this->floor->query(Receipt::class, $company)->create([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'order_id' => $order->getKey(), 'warehouse_id' => $warehouse['id'],
                'item_id' => $style->finished_item_id, 'received_on' => $on->toDateString(), 'pieces' => $pieces, 'is_last' => $last, 'cost_minor' => $cost,
                'currency' => $currency, 'note' => $data['note'] ?? null, 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
            ]);
            foreach ($cells as $key => $quantity) {
                [$size, $color] = explode('|', $key, 2);
                $this->floor->query(ReceiptLine::class, $company)->create([
                    'organization_id' => $company->getKey(), 'receipt_id' => $receipt->getKey(), 'order_id' => $order->getKey(), 'size' => $size, 'color' => $color, 'quantity' => $quantity,
                ]);
            }
            $this->stock->produce($company, $warehouse['id'], $style->finished_item_id, $pieces * 1000, $cost,
                ['module' => 'factory_erp', 'type' => 'receipt', 'id' => $receipt->getKey(), 'actor_id' => $actor->getKey()], $on);
            $journal = $this->postings->finished($company, "factory-receipt-{$receipt->getKey()}", $on,
                __('factory_erp::factory.narration.receipt', ['number' => $order->number]), $receipt->getKey(), $currency, $cost, $order->unit_id);
            $receipt->forceFill(['journal_id' => $journal])->save();
            $this->audit->record('factory_erp.goods_received', $receipt, new: ['order' => $order->number, 'warehouse' => $warehouse['code'], 'pieces' => $pieces, 'cost_minor' => $cost, 'last' => $last],
                actor: $actor, organizationId: $company->getKey());

            return $receipt;
        });
    }
}
