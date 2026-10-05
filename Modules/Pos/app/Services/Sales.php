<?php

namespace Modules\Pos\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Services\TaxCodes;
use Modules\Inventory\Exceptions\InventoryException;
use Modules\Inventory\Services\Stock;
use Modules\Pos\Exceptions\PosException;
use Modules\Pos\Models\Payment;
use Modules\Pos\Models\Register;
use Modules\Pos\Models\Sale;
use Modules\Pos\Models\SaleLine;
use Modules\Pos\Models\Sequence;
use Modules\Pos\Models\Session;

/**
 * Sales and returns at a counter.
 *
 * A sale: items at their price (Inventory's), a discount per line (above
 * rule pos.max_discount_percent of the sale only for pos.supervise), tax
 * from the item's tax code (Accounting's; inside the price or on top, rule
 * pos.prices_include_tax), paid by the counter's methods; change only from
 * cash. Stock leaves the counter's warehouse at its cost; where the company
 * keeps books the sale is posted: payments against pos.sales and
 * pos.tax_output, and the cost from inventory.stock to inventory.cogs.
 *
 * A sale made offline is recorded as it was made: what does not hold now
 * (stock below zero, a shift already closed, a discount over the limit)
 * marks it for review instead of losing it.
 *
 * A return (pos.supervise, within rule pos.return_days): part or all of a
 * sale's lines, worth what they were sold for, paid back exactly; stock
 * comes back at what it left at; the posting is reversed. An op id makes
 * every sale and return safe to send again.
 */
class Sales
{
    public function __construct(
        private Tills $tills,
        private Stock $stock,
        private Sessions $sessions,
        private PosPostings $postings,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{op_id: string, session_id?: string|null, customer_name?: string|null, lines: list<array{item_id: string, quantity_milli: int, discount_minor?: int}>, payments: list<array{method: string, amount_minor: int, reference?: string|null}>}  $data
     */
    public function sell(Organization $company, Register $register, array $data, User $actor, ?CarbonImmutable $madeAt = null): Sale
    {
        $existing = $this->tills->query(Sale::class, $company)->where('op_id', $data['op_id'])->first();
        if ($existing !== null) {
            return $existing;
        }
        $offline = $madeAt !== null;
        $reviews = [];
        if (! $register->is_active) {
            throw PosException::registerInactive();
        }
        $session = $this->sessionFor($company, $register, $data['session_id'] ?? null, $offline, $reviews);
        $context = $this->contexts->forOrganization(Organization::query()->findOrFail($register->unit_id));
        $includeTax = (bool) $this->rules->get('pos.prices_include_tax', $context);

        // Items as Inventory has them now; quantities with no more decimals than the unit allows.
        $items = collect($this->stock->items($company, array_values(array_unique(array_column($data['lines'], 'item_id')))))->keyBy('id');
        $units = $this->stock->units($company);
        $rates = $this->taxRates($company, $items->pluck('tax_code_id')->filter()->unique()->values()->all());
        $priced = [];
        foreach ($data['lines'] as $index => $line) {
            $item = $items[$line['item_id']] ?? throw PosException::itemNotSold($line['item_id']);
            if ($item['sale_price_minor'] === null) {
                throw PosException::itemNotSold($item['sku']);
            }
            $step = 10 ** (3 - min(3, (int) ($units[$item['unit_id']]['decimals'] ?? 0)));
            if ($line['quantity_milli'] <= 0 || $line['quantity_milli'] % $step !== 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity_milli" => __('pos::pos.validation.quantity')]);
            }
            $priced[] = ['quantity_milli' => (int) $line['quantity_milli'], 'unit_price_minor' => (int) $item['sale_price_minor'], 'discount_minor' => (int) ($line['discount_minor'] ?? 0),
                'tax_rate_bp' => $rates[$item['tax_code_id'] ?? ''] ?? 0];
        }
        $totals = Pricing::sale($priced, $includeTax);

        $share = Pricing::discountShare($totals['discount'], $totals['subtotal']);
        $limit = $this->percentToBp((string) $this->rules->get('pos.max_discount_percent', $context));
        if ($share > $limit && ! Gate::forUser($actor)->allows('pos.supervise', Organization::query()->findOrFail($register->unit_id))) {
            if (! $offline) {
                throw PosException::discountLimit((string) $this->rules->get('pos.max_discount_percent', $context));
            }
            $reviews[] = 'discount';
        }

        $settled = $this->settle($register, $totals['total'], $data['payments']);

        return $this->tills->transaction($company, function () use ($company, $register, $session, $data, $actor, $madeAt, $offline, $reviews, $items, $priced, $totals, $settled, $includeTax) {
            $soldAt = $madeAt ?? CarbonImmutable::now();
            $sale = new Sale;
            $sale->fill([
                'organization_id' => $company->getKey(), 'register_id' => $register->getKey(), 'session_id' => $session->getKey(), 'unit_id' => $register->unit_id,
                'number' => $this->number($company, $register, 'sale', $soldAt), 'kind' => 'sale', 'sold_at' => $soldAt, 'customer_name' => $data['customer_name'] ?? null,
                'subtotal_minor' => $totals['subtotal'], 'discount_minor' => $totals['discount'], 'tax_minor' => $totals['tax'], 'total_minor' => $totals['total'],
                'paid_minor' => $settled['paid'], 'change_minor' => $settled['change'], 'currency_code' => $session->currency_code, 'prices_include_tax' => $includeTax,
                'offline' => $offline, 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'], 'version' => 1,
            ])->save();

            $lines = [];
            foreach ($data['lines'] as $index => $entry) {
                $item = $items[$entry['item_id']];
                $line = new SaleLine;
                $line->fill([
                    'organization_id' => $company->getKey(), 'sale_id' => $sale->getKey(), 'line_no' => $index + 1, 'item_id' => $item['id'], 'sku' => $item['sku'],
                    'quantity_milli' => $priced[$index]['quantity_milli'], 'unit_price_minor' => $priced[$index]['unit_price_minor'],
                    'discount_minor' => $totals['lines'][$index]['discount'], 'tax_rate_bp' => $priced[$index]['tax_rate_bp'], 'tax_minor' => $totals['lines'][$index]['tax'],
                    'net_minor' => $totals['lines'][$index]['net'], 'total_minor' => $totals['lines'][$index]['total'],
                ]);
                $line->putTexts('name', $item['names']);
                $line->save();
                $lines[] = $line;
            }
            foreach ($data['payments'] as $payment) {
                (new Payment)->fill(['organization_id' => $company->getKey(), 'sale_id' => $sale->getKey(), 'method' => $payment['method'], 'amount_minor' => (int) $payment['amount_minor'], 'reference' => $payment['reference'] ?? null])->save();
            }

            // Stock leaves; an offline sale already made goes below zero rather than being lost.
            $take = array_map(fn (SaleLine $line) => ['item_id' => $line->item_id, 'quantity_milli' => $line->quantity_milli], $lines);
            $source = ['module' => 'pos', 'type' => 'sale', 'id' => $sale->getKey(), 'actor_id' => $actor->getKey()];
            try {
                $moved = $this->stock->take($company, $register->warehouse_id, $take, $source, $soldAt);
            } catch (InventoryException $exception) {
                if (! $offline || $exception->errorCode() !== 'insufficient_stock') {
                    throw $exception;
                }
                $moved = $this->stock->take($company, $register->warehouse_id, $take, $source, $soldAt, force: true);
                $reviews[] = 'negative_stock';
            }
            foreach ($lines as $index => $line) {
                $line->forceFill(['cost_minor' => $moved['lines'][$index] ?? 0])->save();
            }

            $journal = $this->postings->post($company, "pos-sale-{$sale->getKey()}", $soldAt->startOfDay(), __('pos::pos.narration.sale', ['number' => $sale->number]), 'sale', $sale->getKey(), $sale->currency_code,
                [...$this->paymentLines($data['payments'], $settled['change'], false, $register->unit_id),
                    LedgerLine::credit('pos.sales', $totals['total'] - $totals['tax'], $register->unit_id),
                    LedgerLine::credit('pos.tax_output', $totals['tax']),
                    LedgerLine::debit('inventory.cogs', $moved['cost_minor'], $register->unit_id),
                    LedgerLine::credit('inventory.stock', $moved['cost_minor'], $register->unit_id)]);
            $sale->forceFill(['cost_minor' => $moved['cost_minor'], 'journal_id' => $journal, 'review_reason' => $reviews[0] ?? null])->save();

            $this->addToSession($company, $session, 'sale', $totals['total'], $totals['tax']);
            $this->audit->record('pos.sale_made', $sale, new: ['number' => $sale->number, 'total_minor' => $sale->total_minor, 'offline' => $offline, 'review_reason' => $sale->review_reason],
                actor: $actor, organizationId: $company->getKey());

            return $sale;
        });
    }

    /**
     * Part or all of a sale comes back, paid back exactly.
     *
     * @param  array{op_id: string, reason: string, lines: list<array{line_id: string, quantity_milli: int}>, payments?: list<array{method: string, amount_minor: int, reference?: string|null}>}  $data
     */
    public function giveBack(Organization $company, Sale $sale, Register $register, array $data, User $actor): Sale
    {
        $existing = $this->tills->query(Sale::class, $company)->where('op_id', $data['op_id'])->first();
        if ($existing !== null) {
            return $existing;
        }
        if ($sale->kind !== 'sale') {
            throw PosException::notFound('sale');
        }
        $days = (int) $this->rules->get('pos.return_days', $this->contexts->forOrganization(Organization::query()->findOrFail($register->unit_id)));
        if ($sale->sold_at->addDays($days)->isPast()) {
            throw PosException::returnTooLate($days);
        }
        $session = $this->sessions->current($company, $register) ?? throw PosException::noOpenSession();

        return $this->tills->transaction($company, function () use ($company, $sale, $register, $session, $data, $actor) {
            $original = $this->tills->query(SaleLine::class, $company)->where('sale_id', $sale->getKey())->lockForUpdate()->get()->keyBy('id');
            $rows = [];
            foreach ($data['lines'] as $index => $entry) {
                $line = $original[$entry['line_id']] ?? throw ValidationException::withMessages(["lines.{$index}.line_id" => __('pos::pos.validation.line')]);
                $quantity = (int) $entry['quantity_milli'];
                if ($quantity <= 0 || $quantity > $line->quantity_milli - $line->returned_milli) {
                    throw PosException::returnTooMuch($line->sku);
                }
                $total = Pricing::part($line->total_minor, $quantity, $line->quantity_milli);
                $tax = Pricing::part($line->tax_minor, $quantity, $line->quantity_milli);
                $rows[] = ['line' => $line, 'quantity' => $quantity, 'total' => $total, 'tax' => $tax, 'discount' => Pricing::part($line->discount_minor, $quantity, $line->quantity_milli),
                    'cost' => Pricing::part($line->cost_minor, $quantity, $line->quantity_milli)];
            }
            $total = array_sum(array_column($rows, 'total'));
            $tax = array_sum(array_column($rows, 'tax'));
            $payments = $data['payments'] ?? [['method' => 'cash', 'amount_minor' => $total]];
            foreach ($payments as $payment) {
                if (! in_array($payment['method'], $register->payment_methods, true)) {
                    throw PosException::methodNotTaken($payment['method']);
                }
            }
            if (array_sum(array_column($payments, 'amount_minor')) !== $total) {
                throw ValidationException::withMessages(['payments' => __('pos::pos.validation.refund_total')]);
            }

            $at = CarbonImmutable::now();
            $back = new Sale;
            $back->fill([
                'organization_id' => $company->getKey(), 'register_id' => $register->getKey(), 'session_id' => $session->getKey(), 'unit_id' => $register->unit_id,
                'number' => $this->number($company, $register, 'return', $at), 'kind' => 'return', 'original_sale_id' => $sale->getKey(), 'sold_at' => $at,
                'customer_name' => $sale->customer_name, 'reason' => $data['reason'], 'subtotal_minor' => $total + array_sum(array_column($rows, 'discount')),
                'discount_minor' => array_sum(array_column($rows, 'discount')), 'tax_minor' => $tax, 'total_minor' => $total, 'paid_minor' => $total, 'change_minor' => 0,
                'cost_minor' => array_sum(array_column($rows, 'cost')), 'currency_code' => $sale->currency_code, 'prices_include_tax' => $sale->prices_include_tax,
                'created_by' => $actor->getKey(), 'op_id' => $data['op_id'], 'version' => 1,
            ])->save();
            $take = [];
            foreach ($rows as $index => $row) {
                $line = $row['line'];
                $copy = new SaleLine;
                $copy->fill([
                    'organization_id' => $company->getKey(), 'sale_id' => $back->getKey(), 'line_no' => $index + 1, 'item_id' => $line->item_id, 'sku' => $line->sku,
                    'quantity_milli' => $row['quantity'], 'unit_price_minor' => $line->unit_price_minor, 'discount_minor' => $row['discount'], 'tax_rate_bp' => $line->tax_rate_bp,
                    'tax_minor' => $row['tax'], 'net_minor' => $row['total'] - $row['tax'], 'total_minor' => $row['total'], 'cost_minor' => $row['cost'], 'original_line_id' => $line->getKey(),
                ]);
                $copy->putTexts('name', $line->texts('name'));
                $copy->save();
                $line->forceFill(['returned_milli' => $line->returned_milli + $row['quantity']])->save();
                // Back at what it left at.
                $take[] = ['item_id' => $line->item_id, 'quantity_milli' => -$row['quantity'], 'unit_cost_minor' => $line->quantity_milli > 0 ? intdiv($line->cost_minor * 1000 + intdiv($line->quantity_milli, 2), $line->quantity_milli) : 0];
            }
            foreach ($payments as $payment) {
                (new Payment)->fill(['organization_id' => $company->getKey(), 'sale_id' => $back->getKey(), 'method' => $payment['method'], 'amount_minor' => (int) $payment['amount_minor'], 'reference' => $payment['reference'] ?? null])->save();
            }
            $moved = $this->stock->take($company, $register->warehouse_id, $take, ['module' => 'pos', 'type' => 'return', 'id' => $back->getKey(), 'actor_id' => $actor->getKey()], $at);
            $cost = -$moved['cost_minor'];

            $journal = $this->postings->post($company, "pos-return-{$back->getKey()}", $at->startOfDay(), __('pos::pos.narration.return', ['number' => $back->number, 'sale' => $sale->number]), 'return', $back->getKey(), $back->currency_code,
                [...$this->paymentLines($payments, 0, true, $register->unit_id),
                    LedgerLine::debit('pos.sales', $total - $tax, $register->unit_id),
                    LedgerLine::debit('pos.tax_output', $tax),
                    LedgerLine::debit('inventory.stock', $cost, $register->unit_id),
                    LedgerLine::credit('inventory.cogs', $cost, $register->unit_id)]);
            $back->forceFill(['cost_minor' => $cost, 'journal_id' => $journal])->save();

            $this->addToSession($company, $session, 'return', $total, 0);
            $this->audit->record('pos.return_made', $back, new: ['number' => $back->number, 'sale' => $sale->number, 'total_minor' => $total], reason: $data['reason'], actor: $actor, organizationId: $company->getKey());

            return $back;
        });
    }

    /**
     * The shift a sale belongs to: the counter's open one; offline, the one
     * the device had (a shift closed since marks the sale for review).
     *
     * @param  list<string>  $reviews
     */
    private function sessionFor(Organization $company, Register $register, ?string $sessionId, bool $offline, array &$reviews): Session
    {
        if ($offline && $sessionId !== null) {
            $session = $this->tills->query(Session::class, $company)->whereKey($sessionId)->where('register_id', $register->getKey())->first();
            if ($session !== null) {
                if ($session->status !== Session::OPEN) {
                    $reviews[] = 'late_session';
                }

                return $session;
            }
        }

        return $this->sessions->current($company, $register) ?? throw PosException::noOpenSession();
    }

    /**
     * Paid against owed: methods the counter takes, enough, change from cash only.
     *
     * @param  list<array{method: string, amount_minor: int}>  $payments
     * @return array{paid: int, change: int, cash: int}
     */
    private function settle(Register $register, int $owed, array $payments): array
    {
        foreach ($payments as $payment) {
            if (! in_array($payment['method'], $register->payment_methods, true)) {
                throw PosException::methodNotTaken($payment['method']);
            }
        }
        $settled = Pricing::settle($owed, $payments);
        if ($settled === null) {
            throw PosException::underpaid((string) $owed);
        }
        if ($settled['change'] > $settled['cash']) {
            throw PosException::changeWithoutCash();
        }

        return $settled;
    }

    /**
     * Each method's money in (a sale; cash less the change) or out (a return).
     *
     * @param  list<array{method: string, amount_minor: int}>  $payments
     * @return list<LedgerLine>
     */
    private function paymentLines(array $payments, int $change, bool $out, string $unitId): array
    {
        $byMethod = [];
        foreach ($payments as $payment) {
            $byMethod[$payment['method']] = ($byMethod[$payment['method']] ?? 0) + (int) $payment['amount_minor'];
        }
        if ($change > 0) {
            $byMethod['cash'] -= $change;
        }
        $lines = [];
        foreach ($byMethod as $method => $amount) {
            $key = PosPostings::METHOD_KEYS[$method];
            $lines[] = $out ? LedgerLine::credit($key, $amount, $unitId) : LedgerLine::debit($key, $amount, $unitId);
        }

        return $lines;
    }

    /** @return array<string, int> */
    private function taxRates(Organization $company, array $ids): array
    {
        return $ids === [] || ! $this->modules->isEnabled('accounting', $company) ? [] : app(TaxCodes::class)->salesRates($company, $ids);
    }

    private function addToSession(Organization $company, Session $session, string $kind, int $total, int $tax): void
    {
        $row = $this->tills->query(Session::class, $company)->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
        if ($kind === 'sale') {
            $row->forceFill(['sales_count' => $row->sales_count + 1, 'sales_minor' => $row->sales_minor + $total, 'tax_minor' => $row->tax_minor + $tax]);
        } else {
            $row->forceFill(['returns_minor' => $row->returns_minor + $total]);
        }
        $row->save();
    }

    /** "R-SHOP1-2026-000001": the kind's prefix (rule pos.number_prefixes), the counter, the year, a running number. */
    private function number(Organization $company, Register $register, string $kind, CarbonImmutable $on): string
    {
        $year = (int) $on->format('Y');
        $sequence = $this->tills->query(Sequence::class, $company)->where('register_id', $register->getKey())->where('kind', $kind)->where('year', $year)->lockForUpdate()->first();
        if ($sequence === null) {
            $sequence = new Sequence;
            $sequence->fill(['organization_id' => $company->getKey(), 'register_id' => $register->getKey(), 'kind' => $kind, 'year' => $year, 'next' => 1]);
        }
        $number = $sequence->next;
        $sequence->next = $number + 1;
        $sequence->save();
        $prefixes = (array) $this->rules->get('pos.number_prefixes', $this->contexts->forOrganization($company));

        return sprintf('%s-%s-%d-%06d', $prefixes[$kind] ?? strtoupper($kind[0]), $register->code, $year, $number);
    }

    /** "12.5" (%) -> 1250 basis points (string maths). */
    private function percentToBp(string $text): int
    {
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', trim($text), $match)) {
            return 0;
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }
}
