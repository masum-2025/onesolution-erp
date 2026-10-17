<?php

namespace Modules\FactoryErp\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Accounting\Services\Customers;
use Modules\Crm\Services\Organizations;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\LetterOfCredit;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Sequence;
use Modules\FactoryErp\Models\Shipment;
use Modules\FactoryErp\Models\ShipmentCarton;
use Modules\FactoryErp\Models\Style;
use Modules\Inventory\Services\Stock;

/**
 * Shipments: finished goods of an order in production leaving for the
 * buyer, with a packing list (runs of cartons, each one size and colour).
 * A size and colour ships at most what is in the store as finished goods
 * and not shipped yet. Numbered per year (rule
 * factory_erp.shipment_number_prefix).
 *
 * Shipping (once; then the shipment never changes): the order's last
 * final inspection must have passed where the company needs it; the
 * pieces leave the store through Inventory (Stock::take) and their cost
 * goes to cost of sales; where the company keeps books, a draft invoice to
 * the buyer is made through Accounting (Customers), in the company's
 * currency at the shipment's exchange rate, for an accountant to check and
 * post. The order's last shipment marks the order shipped.
 */
class Shipments
{
    public function __construct(
        private Floor $floor,
        private Progress $progress,
        private Quality $quality,
        private Stock $stock,
        private FactoryPostings $postings,
        private Customers $customers,
        private Organizations $buyers,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private LettersOfCredit $lcs,
    ) {}

    /** What a shipment's form may set (the order, currency and number are fixed on making). */
    private const FIELDS = ['lc_id', 'ship_on', 'mode', 'carrier', 'transport_ref', 'destination', 'export_permit_no', 'export_permit_on', 'warehouse_id', 'is_last', 'note'];

    /** @return Collection<int, Shipment> */
    public function of(Organization $company, Order $order): Collection
    {
        return $this->floor->query(Shipment::class, $company)->where('order_id', $order->getKey())->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /** @return Collection<int, ShipmentCarton> */
    public function cartons(Organization $company, Shipment $shipment): Collection
    {
        return $this->floor->query(ShipmentCarton::class, $company)->where('shipment_id', $shipment->getKey())->orderBy('sort_order')->get();
    }

    /**
     * Pieces per "size|colour" in shipped shipments of the order.
     *
     * @return array<string, int>
     */
    public function shipped(Organization $company, Order $order): array
    {
        $shipped = $this->floor->query(Shipment::class, $company)->where('order_id', $order->getKey())->where('status', 'shipped')->pluck('id');
        $cells = [];
        foreach ($this->floor->query(ShipmentCarton::class, $company)->whereIn('shipment_id', $shipped)->get() as $carton) {
            $key = "{$carton->size}|{$carton->color}";
            $cells[$key] = ($cells[$key] ?? 0) + $carton->totalPieces();
        }

        return $cells;
    }

    /** A price in one currency's minor units in another's at a rate (millionths), half up. */
    public static function convert(int $minor, string $from, string $to, int $rateMicro): int
    {
        if ($from === $to) {
            return $minor;
        }
        $top = $minor * $rateMicro * (10 ** MoneyText::fractionDigits($to));
        $bottom = 1000000 * (10 ** MoneyText::fractionDigits($from));

        return intdiv(2 * $top + $bottom, 2 * $bottom);
    }

    /**
     * Make (shipment null) or change a draft shipment; cartons, when given, replace its packing list.
     *
     * @param  array<string, mixed>  $data  Validated by ShipmentRequest.
     */
    public function save(Organization $company, Organization $unit, ?Shipment $shipment, array $data, User $actor): Shipment
    {
        if ($shipment === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->floor->query(Shipment::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        $units = $this->floor->subtreeIds($unit);
        $order = $this->floor->query(Order::class, $company)->whereKey($shipment?->order_id ?? $data['order_id'])->whereIn('unit_id', $units)->first()
            ?? throw FactoryException::notFound('order');
        if (isset($data['warehouse_id'])) {
            $warehouse = $this->stock->warehouse($company, $data['warehouse_id']);
            if ($warehouse === null || ! $warehouse['is_active']) {
                throw FactoryException::notFound('warehouse');
            }
        }
        $own = $this->floor->currency($company);
        $rate = null;
        if (array_key_exists('exchange_rate', $data) || $shipment === null) {
            $rate = $order->currency === $own ? 1000000 : self::rateMicro($data['exchange_rate'] ?? null);
        }
        $style = $this->floor->query(Style::class, $company)->whereKey($order->style_id)->firstOrFail();
        $cartons = isset($data['cartons']) ? $this->checkedCartons($style, $data['cartons']) : null;
        $context = $this->contexts->forOrganization($unit);

        return $this->floor->transaction($company, function () use ($company, $unit, $shipment, $order, $data, $rate, $cartons, $context, $actor) {
            $order = $this->floor->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status !== 'in_production') {
                throw FactoryException::wrongStatus();
            }
            if ($shipment !== null) {
                $shipment = $this->floor->query(Shipment::class, $company)->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();
                if ($shipment->version !== (int) $data['base_version']) {
                    throw FactoryException::versionConflict(['version' => $shipment->version]);
                }
                if ($shipment->status !== 'draft') {
                    throw FactoryException::wrongStatus();
                }
                $shipment->fill(array_intersect_key($data, array_flip(self::FIELDS)));
                $shipment->version++;
            } else {
                $shipment = new Shipment([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'order_id' => $order->getKey(), 'currency' => $order->currency,
                    'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(self::FIELDS)),
                ]);
                // Under the order's L/C unless another is named.
                if (! array_key_exists('lc_id', $data)) {
                    $shipment->lc_id = $this->lcs->forOrder($company, $order)?->getKey();
                }
                $shipment->number = $this->number($company, $context, CarbonImmutable::parse($data['ship_on'], 'UTC'));
            }
            if ($rate !== null) {
                $shipment->rate_micro = $rate;
            }
            // An L/C the order is paid under (the buyer's, in the order's currency).
            if ($shipment->lc_id !== null && ! in_array($order->getKey(), $this->lcs->orderIds($company, $this->floor->query(LetterOfCredit::class, $company)->findOrFail($shipment->lc_id)), true)) {
                throw FactoryException::lcNotForOrder($order->number);
            }
            if ($cartons !== null) {
                $this->assertAvailable($company, $order, $cartons);
                $shipment->pieces = array_sum(array_map(fn (array $carton) => ($carton['to_no'] - $carton['from_no'] + 1) * $carton['pieces_each'], $cartons));
                $shipment->value_minor = $shipment->pieces * (int) $order->unit_price_minor;
            }
            $shipment->save();
            if ($cartons !== null) {
                $this->floor->query(ShipmentCarton::class, $company)->where('shipment_id', $shipment->getKey())->delete();
                foreach ($cartons as $index => $carton) {
                    $this->floor->query(ShipmentCarton::class, $company)->create([
                        'organization_id' => $company->getKey(), 'shipment_id' => $shipment->getKey(), 'order_id' => $order->getKey(), 'sort_order' => $index, ...$carton,
                    ]);
                }
            }
            $this->audit->record($shipment->wasRecentlyCreated ? 'factory_erp.shipment_created' : 'factory_erp.shipment_updated', $shipment,
                new: $shipment->only(['number', 'ship_on', 'mode', 'pieces', 'value_minor', 'currency', 'rate_micro', 'is_last']), actor: $actor, organizationId: $company->getKey());

            return $shipment;
        });
    }

    /**
     * Ship: stock out at cost, cost of sales, a draft invoice to the buyer, and the order shipped with its last shipment.
     * The country's export permit must be written where it needs one; an L/C's discrepancies are refused or accepted.
     */
    public function ship(Organization $company, Shipment $shipment, int $baseVersion, User $actor, bool $acceptDiscrepancy = false): Shipment
    {
        $own = $this->floor->currency($company);

        return $this->floor->transaction($company, function () use ($company, $shipment, $baseVersion, $own, $actor, $acceptDiscrepancy) {
            $shipment = $this->floor->query(Shipment::class, $company)->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();
            if ($shipment->version !== $baseVersion) {
                throw FactoryException::versionConflict(['version' => $shipment->version]);
            }
            $order = $this->floor->query(Order::class, $company)->whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
            if ($shipment->status !== 'draft' || $order->status !== 'in_production') {
                throw FactoryException::wrongStatus();
            }
            $cartons = $this->cartons($company, $shipment);
            if ($cartons->isEmpty()) {
                throw FactoryException::noPieces();
            }
            if ($this->rules->get('factory_erp.ship_needs_final_pass', $this->contexts->forOrganization($company))
                && $this->quality->lastFinal($company, $order)?->result !== 'pass') {
                throw FactoryException::finalNotPassed();
            }
            $cells = [];
            foreach ($cartons as $carton) {
                $key = "{$carton->size}|{$carton->color}";
                $cells[$key] = ($cells[$key] ?? 0) + $carton->totalPieces();
            }
            $this->assertAvailable($company, $order, array_map(fn (string $key, int $pieces) => [
                'size' => explode('|', $key, 2)[0], 'color' => explode('|', $key, 2)[1], 'from_no' => 1, 'to_no' => 1, 'pieces_each' => $pieces,
            ], array_keys($cells), $cells));
            $style = $this->floor->query(Style::class, $company)->whereKey($order->style_id)->firstOrFail();
            if ($style->finished_item_id === null) {
                throw FactoryException::noFinishedItem();
            }
            $unitContext = $this->contexts->forOrganization(Organization::query()->find($shipment->unit_id) ?? $company);
            if ($this->rules->get('factory_erp.export_permit_required', $unitContext) && trim((string) $shipment->export_permit_no) === '') {
                throw FactoryException::exportPermitNeeded((string) $this->rules->get('factory_erp.export_permit_label', $unitContext));
            }
            $discrepancies = [];
            if ($shipment->lc_id !== null) {
                $lc = $this->floor->query(LetterOfCredit::class, $company)->findOrFail($shipment->lc_id);
                $discrepancies = $this->lcs->discrepancies($company, $lc, $shipment, $this->floor->today($company));
                if ($discrepancies !== []) {
                    $strict = (bool) $this->rules->get('factory_erp.lc_strict', $unitContext);
                    if ($strict || ! $acceptDiscrepancy) {
                        throw FactoryException::lcDiscrepancy($discrepancies, $strict);
                    }
                }
            }
            $on = $shipment->ship_on;
            $moved = $this->stock->take($company, $shipment->warehouse_id, [['item_id' => $style->finished_item_id, 'quantity_milli' => $shipment->pieces * 1000]],
                ['module' => 'factory_erp', 'type' => 'shipment', 'id' => $shipment->getKey(), 'actor_id' => $actor->getKey()], $on);
            $cost = (int) $moved['cost_minor'];
            $journal = $this->postings->sold($company, "factory-shipment-{$shipment->getKey()}", $on,
                __('factory_erp::factory.narration.shipment', ['number' => $shipment->number, 'order' => $order->number]), $shipment->getKey(), $own, $cost, $order->unit_id);
            $invoice = $this->invoice($company, $shipment, $order, $style, $cells, $own, $actor);

            $shipment->forceFill(['status' => 'shipped', 'shipped_at' => now(), 'cost_minor' => $cost, 'journal_id' => $journal, 'invoice_id' => $invoice,
                'discrepancies' => $discrepancies ?: null, 'version' => $shipment->version + 1])->save();
            $this->audit->record('factory_erp.shipment_shipped', $shipment,
                new: ['number' => $shipment->number, 'order' => $order->number, 'pieces' => $shipment->pieces, 'value_minor' => $shipment->value_minor, 'currency' => $shipment->currency,
                    'cost_minor' => $cost, 'invoice_id' => $invoice, 'lc_id' => $shipment->lc_id, 'discrepancies_accepted' => $discrepancies], actor: $actor, organizationId: $company->getKey());
            if ($shipment->is_last) {
                $order->forceFill(['status' => 'shipped', 'version' => $order->version + 1])->save();
                $this->audit->record('factory_erp.order_shipped', $order, old: ['status' => 'in_production'], new: ['status' => 'shipped'], actor: $actor, organizationId: $company->getKey());
            }

            return $shipment;
        });
    }

    /**
     * A draft invoice to the buyer in the company's currency, one line per size and colour; none where the company keeps no books.
     *
     * @param  array<string, int>  $cells
     */
    private function invoice(Organization $company, Shipment $shipment, Order $order, Style $style, array $cells, string $own, User $actor): ?string
    {
        if (! $this->customers->available($company)) {
            return null;
        }
        $buyer = $this->buyers->many($company, [$order->buyer_id])[$order->buyer_id] ?? ['name' => '—', 'email' => null, 'phone' => null];
        $party = $this->customers->forContact($company, $order->buyer_id, ['name' => $buyer['name'], 'email' => $buyer['email'] ?? null, 'phone' => $buyer['phone'] ?? null], $actor);
        $price = self::convert((int) $order->unit_price_minor, $order->currency, $own, $shipment->rate_micro);
        $locale = app()->getLocale();
        $foreign = $order->currency === $own ? '' : ' · '.MoneyText::shown((int) $order->unit_price_minor, $order->currency, $locale).' × '.self::rateText($shipment->rate_micro);
        $invoice = $this->customers->draftInvoice($company, [
            'party_id' => $party, 'issue_date' => $shipment->ship_on->toDateString(), 'reference' => trim($shipment->number.' '.($order->po_number ?? '')),
            'notes' => __('factory_erp::factory.invoice_note', ['order' => $order->number, 'value' => MoneyText::shown($shipment->value_minor, $shipment->currency, $locale)]),
            'income_key' => FactoryPostings::EXPORT_SALES,
            'lines' => collect($cells)->map(fn (int $pieces, string $key) => [
                'description' => "{$style->code} {$style->name} · ".str_replace('|', ' / ', $key).$foreign,
                'quantity_milli' => $pieces * 1000, 'unit_price_minor' => $price,
            ])->values()->all(),
        ], $actor);

        return $invoice['id'];
    }

    /**
     * Each size and colour at most what the store holds as the order's finished goods and is not shipped yet.
     *
     * @param  list<array{size: string, color: string, from_no: int, to_no: int, pieces_each: int}>  $cartons
     */
    private function assertAvailable(Organization $company, Order $order, array $cartons): void
    {
        $progress = $this->progress->of($company, $order);
        $shipped = $this->shipped($company, $order);
        $wanted = [];
        foreach ($cartons as $carton) {
            $key = "{$carton['size']}|{$carton['color']}";
            $wanted[$key] = ($wanted[$key] ?? 0) + ($carton['to_no'] - $carton['from_no'] + 1) * $carton['pieces_each'];
        }
        foreach ($wanted as $key => $pieces) {
            $left = ($progress[$key]['received'] ?? 0) - ($shipped[$key] ?? 0);
            if ($pieces > $left) {
                [$size, $color] = explode('|', $key, 2);

                throw FactoryException::overStage('shipped', $size, $color, $left);
            }
        }
    }

    /**
     * Cartons of sizes and colours of the style, runs from-to not overlapping.
     *
     * @param  list<array<string, mixed>>  $cartons
     * @return list<array<string, int|string>>
     */
    private function checkedCartons(Style $style, array $cartons): array
    {
        $runs = [];
        $checked = [];
        foreach ($cartons as $carton) {
            foreach (['size' => 'sizes', 'color' => 'colors'] as $field => $list) {
                if (! in_array($carton[$field], $style->{$list}, true)) {
                    throw FactoryException::notInStyle((string) $carton[$field]);
                }
            }
            $from = (int) $carton['from_no'];
            $to = (int) $carton['to_no'];
            if ($to < $from) {
                throw FactoryException::cartonRun($from, $to);
            }
            foreach ($runs as [$a, $b]) {
                if ($from <= $b && $to >= $a) {
                    throw FactoryException::cartonRun($from, $to);
                }
            }
            $runs[] = [$from, $to];
            $checked[] = [
                'from_no' => $from, 'to_no' => $to, 'size' => $carton['size'], 'color' => $carton['color'], 'pieces_each' => (int) $carton['pieces_each'],
                'net_grams_each' => (int) ($carton['net_grams_each'] ?? 0), 'gross_grams_each' => (int) ($carton['gross_grams_each'] ?? 0),
                'length_mm' => (int) ($carton['length_mm'] ?? 0), 'width_mm' => (int) ($carton['width_mm'] ?? 0), 'height_mm' => (int) ($carton['height_mm'] ?? 0),
            ];
        }

        return $checked;
    }

    /** "110.25" -> 110250000; refused when missing or not above zero (six decimals at most). */
    public static function rateMicro(?string $text): int
    {
        if ($text === null || ! preg_match('/^(\d{1,7})(?:\.(\d{1,6}))?$/', trim($text), $match) || ($micro = (int) $match[1] * 1000000 + (int) str_pad($match[2] ?? '', 6, '0')) <= 0) {
            throw FactoryException::rateNeeded();
        }

        return $micro;
    }

    /** 110250000 -> "110.25" */
    public static function rateText(int $micro): string
    {
        $rest = rtrim(str_pad((string) ($micro % 1000000), 6, '0', STR_PAD_LEFT), '0');

        return intdiv($micro, 1000000).($rest === '' ? '' : ".{$rest}");
    }

    private function number(Organization $company, $context, CarbonImmutable $on): string
    {
        $year = (int) $on->format('Y');
        $sequence = $this->floor->query(Sequence::class, $company)->where('kind', 'shipment')->where('year', $year)->lockForUpdate()->first()
            ?? new Sequence(['organization_id' => $company->getKey(), 'kind' => 'shipment', 'year' => $year, 'next' => 1]);
        $number = $sequence->next;
        $sequence->next = $number + 1;
        $sequence->save();

        return sprintf('%s-%d-%05d', (string) $this->rules->get('factory_erp.shipment_number_prefix', $context), $year, $number);
    }
}
