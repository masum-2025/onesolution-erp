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
use Modules\FactoryErp\Models\Issue;
use Modules\FactoryErp\Models\IssueLine;
use Modules\FactoryErp\Models\Order;
use Modules\Inventory\Services\Stock;

/**
 * Materials from a store to the floor for a confirmed or running order
 * (issue), or back from it (return), through Inventory's Stock service at
 * what it values them; the value moves between stock and work in progress
 * in the books. Only items of the style's bill of materials go; more than
 * the order needs is refused or asked again (rule factory_erp.over_issue);
 * a return is at most what is on the floor, at its share of what that cost,
 * so returning everything leaves nothing behind in work in progress.
 * Append-only: a mistake is put right by a return.
 */
class Issues
{
    public function __construct(
        private Floor $floor,
        private Requirements $requirements,
        private Stock $stock,
        private FactoryPostings $postings,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** @return Collection<int, Issue> */
    public function of(Organization $company, Order $order): Collection
    {
        return $this->floor->query(Issue::class, $company)->where('order_id', $order->getKey())->orderByDesc('issued_on')->orderByDesc('id')->get();
    }

    /**
     * @param  list<string>  $issueIds
     * @return Collection<string, Collection<int, IssueLine>>
     */
    public function lines(Organization $company, array $issueIds): Collection
    {
        return $this->floor->query(IssueLine::class, $company)->whereIn('issue_id', $issueIds)->orderBy('id')->get()->groupBy('issue_id');
    }

    /**
     * @param  array<string, mixed>  $data  Validated by IssueRequest.
     */
    public function record(Organization $company, Organization $unit, Order $order, array $data, User $actor): Issue
    {
        if (($data['op_id'] ?? null) !== null) {
            $done = $this->floor->query(Issue::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        $warehouse = $this->stock->warehouse($company, $data['warehouse_id']);
        if ($warehouse === null || ! $warehouse['is_active']) {
            throw FactoryException::notFound('warehouse');
        }
        $today = $this->floor->today($company);
        $on = isset($data['issued_on']) ? CarbonImmutable::parse($data['issued_on'], 'UTC') : $today;
        if ($on->greaterThan($today)) {
            throw FactoryException::futureDate('issued_on');
        }
        $quantities = [];
        foreach ($data['lines'] as $line) {
            $quantities[$line['item_id']] = ($quantities[$line['item_id']] ?? 0) + (int) $line['quantity_milli'];
        }
        $kind = $data['kind'];
        $overRule = (string) $this->rules->get('factory_erp.over_issue', $this->contexts->forOrganization($unit));
        $currency = $this->floor->currency($company);

        return $this->floor->transaction($company, function () use ($company, $unit, $order, $data, $warehouse, $on, $quantities, $kind, $overRule, $currency, $actor) {
            // One issue at a time per order: what is on the floor is read and changed together.
            $order = $this->floor->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['confirmed', 'in_production'], true)) {
                throw FactoryException::wrongStatus();
            }
            $needs = $this->requirements->needs($company, $order);
            $issued = $this->requirements->issued($company, $order);
            $over = [];
            if ($kind === 'issue') {
                if (array_diff_key($quantities, $needs) !== []) {
                    throw FactoryException::notInBom();
                }
                foreach ($quantities as $itemId => $quantity) {
                    $already = $issued[$itemId]['quantity'] ?? 0;
                    if ($already + $quantity > $needs[$itemId]) {
                        $over[] = ['item_id' => $itemId, 'need_milli' => $needs[$itemId], 'issued_milli' => $already, 'quantity_milli' => $quantity];
                    }
                }
                if ($over !== [] && ($overRule === 'block' || empty($data['allow_over']))) {
                    throw FactoryException::overIssue($over, $overRule === 'block');
                }
            } else {
                foreach ($quantities as $itemId => $quantity) {
                    if ($quantity > ($issued[$itemId]['quantity'] ?? 0)) {
                        throw FactoryException::tooMuchBack();
                    }
                }
            }

            $issue = $this->floor->query(Issue::class, $company)->create([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'order_id' => $order->getKey(), 'warehouse_id' => $warehouse['id'],
                'kind' => $kind, 'issued_on' => $on->toDateString(), 'currency' => $currency, 'note' => $data['note'] ?? null,
                'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
            ]);
            $take = [];
            foreach ($quantities as $itemId => $quantity) {
                $take[] = $kind === 'issue'
                    ? ['item_id' => $itemId, 'quantity_milli' => $quantity]
                    // Back at its share of what is on the floor: all of it, all of its cost.
                    : ['item_id' => $itemId, 'quantity_milli' => -$quantity,
                        'value_minor' => intdiv(($issued[$itemId]['cost'] ?? 0) * $quantity, $issued[$itemId]['quantity'])];
            }
            $moved = $this->stock->take($company, $warehouse['id'], $take, ['module' => 'factory_erp', 'type' => 'issue', 'id' => $issue->getKey(), 'actor_id' => $actor->getKey()], $on);
            foreach (array_values($take) as $index => $line) {
                $this->floor->query(IssueLine::class, $company)->create([
                    'organization_id' => $company->getKey(), 'issue_id' => $issue->getKey(), 'order_id' => $order->getKey(), 'item_id' => $line['item_id'],
                    'quantity_milli' => abs($line['quantity_milli']), 'cost_minor' => abs($moved['lines'][$index] ?? 0),
                ]);
            }
            $value = (int) $moved['cost_minor'];
            $journal = $this->postings->materials($company, "factory-issue-{$issue->getKey()}", $on,
                __("factory_erp::factory.narration.{$kind}", ['number' => $order->number]), 'issue', $issue->getKey(), $currency, $value, $order->unit_id);
            $issue->forceFill(['cost_minor' => abs($value), 'journal_id' => $journal])->save();
            $this->audit->record($kind === 'issue' ? 'factory_erp.materials_issued' : 'factory_erp.materials_returned', $issue,
                new: ['order' => $order->number, 'warehouse' => $warehouse['code'], 'lines' => count($take), 'cost_minor' => abs($value), 'over_need' => $over !== []],
                actor: $actor, organizationId: $company->getKey());

            return $issue;
        });
    }
}
