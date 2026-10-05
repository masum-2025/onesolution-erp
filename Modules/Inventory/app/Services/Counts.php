<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Events\StockMoved;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Move;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\StockCountLine;
use Modules\Inventory\Models\Warehouse;

/**
 * Stock counts. Opening one takes what the books expect of every stock item
 * held in the warehouse (or one category); people enter what they counted
 * (items not expected can be added); someone else approves (inventory.approve)
 * and each difference is posted as a count move at today's cost, against
 * inventory.adjustment. One open count per warehouse at a time. Lines not
 * counted change nothing.
 */
class Counts
{
    public function __construct(
        private Inventories $inventories,
        private StockLedger $ledger,
        private Numbers $numbers,
        private InventoryPostings $postings,
        private AuditLogger $audit,
    ) {}

    public function open(Organization $company, Warehouse $warehouse, ?string $categoryId, CarbonImmutable $on, User $actor): StockCount
    {
        return $this->inventories->transaction($company, function () use ($company, $warehouse, $categoryId, $on, $actor) {
            if ($this->inventories->query(StockCount::class, $company)->where('warehouse_id', $warehouse->getKey())->whereIn('status', [StockCount::COUNTING, StockCount::PENDING])->exists()) {
                throw InventoryException::countOpen();
            }
            $count = new StockCount;
            $count->fill([
                'organization_id' => $company->getKey(), 'number' => $this->numbers->next($company, 'count', $on), 'warehouse_id' => $warehouse->getKey(),
                'category_id' => $categoryId, 'status' => StockCount::COUNTING, 'counted_on' => $on->toDateString(),
                'currency_code' => $this->inventories->currency($company), 'created_by' => $actor->getKey(), 'version' => 1,
            ])->save();

            $items = $this->inventories->query(Item::class, $company)->where('kind', 'stock')->where('is_active', true)
                ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))->pluck('id')->all();
            $balances = $this->inventories->query(Balance::class, $company)->where('warehouse_id', $warehouse->getKey())->whereIn('item_id', $items)->get();
            foreach ($balances as $balance) {
                (new StockCountLine)->fill(['organization_id' => $company->getKey(), 'count_id' => $count->getKey(), 'item_id' => $balance->item_id, 'expected_milli' => $balance->quantity_milli])->save();
            }
            $this->audit->record('inventory.count_opened', $count, new: ['warehouse_id' => $warehouse->getKey(), 'lines' => $balances->count()], actor: $actor, organizationId: $company->getKey());

            return $count;
        });
    }

    /**
     * What was counted (null = not counted); an item not expected joins with nothing expected.
     *
     * @param  list<array{item_id: string, counted_milli: int|null}>  $lines
     */
    public function record(Organization $company, StockCount $count, int $baseVersion, array $lines, User $actor): StockCount
    {
        return $this->inventories->transaction($company, function () use ($company, $count, $baseVersion, $lines) {
            $count = $this->locked($company, $count, $baseVersion, [StockCount::COUNTING]);
            $existing = $this->linesOf($company, $count)->keyBy('item_id');
            $items = $this->inventories->query(Item::class, $company)->whereIn('id', array_column($lines, 'item_id'))->where('kind', 'stock')->pluck('id')->all();
            foreach ($lines as $index => $entry) {
                if (! in_array($entry['item_id'], $items, true)) {
                    throw ValidationException::withMessages(["lines.{$index}.item_id" => __('inventory::inventory.validation.item')]);
                }
                $line = $existing[$entry['item_id']] ?? (new StockCountLine)->fill(['organization_id' => $company->getKey(), 'count_id' => $count->getKey(), 'item_id' => $entry['item_id'], 'expected_milli' => 0]);
                $line->counted_milli = $entry['counted_milli'];
                $line->save();
            }
            $count->forceFill(['version' => $count->version + 1])->save();

            return $count;
        });
    }

    public function submit(Organization $company, StockCount $count, int $baseVersion, User $actor): StockCount
    {
        return $this->inventories->transaction($company, function () use ($company, $count, $baseVersion, $actor) {
            $count = $this->locked($company, $count, $baseVersion, [StockCount::COUNTING]);
            $count->forceFill(['status' => StockCount::PENDING, 'submitted_by' => $actor->getKey(), 'reject_reason' => null, 'version' => $count->version + 1])->save();
            $this->audit->record('inventory.count_submitted', $count, new: ['number' => $count->number], actor: $actor, organizationId: $company->getKey());

            return $count;
        });
    }

    /** Someone else approves: each difference is posted. */
    public function approve(Organization $company, StockCount $count, int $baseVersion, User $actor): StockCount
    {
        return $this->inventories->transaction($company, function () use ($company, $count, $baseVersion, $actor) {
            $count = $this->locked($company, $count, $baseVersion, [StockCount::PENDING]);
            if (in_array($actor->getKey(), [$count->created_by, $count->submitted_by], true)) {
                throw InventoryException::ownDocument();
            }
            $warehouse = $this->inventories->query(Warehouse::class, $company)->findOrFail($count->warehouse_id);
            $total = 0;
            foreach ($this->linesOf($company, $count) as $line) {
                if ($line->counted_milli === null || $line->counted_milli === $line->expected_milli) {
                    continue;
                }
                $item = $this->inventories->query(Item::class, $company)->findOrFail($line->item_id);
                $moves = $this->ledger->move($company, $item, $warehouse, 'count', $line->counted_milli - $line->expected_milli, null,
                    ['source_module' => 'inventory', 'source_type' => 'count', 'source_id' => $count->getKey(), 'actor_id' => $actor->getKey()], $count->counted_on);
                $value = array_sum(array_map(fn (Move $move) => $move->value_minor, $moves));
                $line->forceFill(['value_minor' => $value])->save();
                $total += $value;
            }
            $journal = $this->postings->post($company, "inventory-count-{$count->getKey()}", $count->counted_on, __('inventory::inventory.narration.count', ['number' => $count->number]),
                'count', $count->getKey(), $count->currency_code, InventoryPostings::against('inventory.adjustment', $total, $warehouse->unit_id));
            $count->forceFill(['status' => StockCount::POSTED, 'approved_by' => $actor->getKey(), 'variance_value_minor' => $total, 'posted_at' => now(), 'journal_id' => $journal, 'version' => $count->version + 1])->save();
            $this->audit->record('inventory.count_posted', $count, new: ['number' => $count->number, 'variance_value_minor' => $total, 'journal_id' => $journal], actor: $actor, organizationId: $company->getKey());
            StockMoved::dispatch($company->getKey(), 'count', $count->getKey());

            return $count;
        });
    }

    public function reject(Organization $company, StockCount $count, int $baseVersion, string $reason, User $actor): StockCount
    {
        return $this->inventories->transaction($company, function () use ($company, $count, $baseVersion, $reason, $actor) {
            $count = $this->locked($company, $count, $baseVersion, [StockCount::PENDING]);
            if (in_array($actor->getKey(), [$count->created_by, $count->submitted_by], true)) {
                throw InventoryException::ownDocument();
            }
            $count->forceFill(['status' => StockCount::COUNTING, 'reject_reason' => $reason, 'submitted_by' => null, 'version' => $count->version + 1])->save();
            $this->audit->record('inventory.count_rejected', $count, new: ['number' => $count->number], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $count;
        });
    }

    public function cancel(Organization $company, StockCount $count, int $baseVersion, User $actor): StockCount
    {
        return $this->inventories->transaction($company, function () use ($company, $count, $baseVersion, $actor) {
            $count = $this->locked($company, $count, $baseVersion, [StockCount::COUNTING, StockCount::PENDING]);
            $count->forceFill(['status' => StockCount::CANCELLED, 'version' => $count->version + 1])->save();
            $this->audit->record('inventory.count_cancelled', $count, new: ['number' => $count->number], actor: $actor, organizationId: $company->getKey());

            return $count;
        });
    }

    /** @return Collection<int, StockCountLine> */
    public function linesOf(Organization $company, StockCount $count): Collection
    {
        return $this->inventories->query(StockCountLine::class, $company)->where('count_id', $count->getKey())->get();
    }

    /**
     * @param  list<string>  $statuses
     */
    private function locked(Organization $company, StockCount $count, ?int $baseVersion, array $statuses): StockCount
    {
        /** @var StockCount $fresh */
        $fresh = $this->inventories->query(StockCount::class, $company)->whereKey($count->getKey())->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw InventoryException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }
        if (! in_array($fresh->status, $statuses, true)) {
            throw InventoryException::wrongStatus($fresh->status);
        }

        return $fresh;
    }
}
