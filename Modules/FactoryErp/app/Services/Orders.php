<?php

namespace Modules\FactoryErp\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Crm\Services\Organizations;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;
use Modules\FactoryErp\Models\Sequence;
use Modules\FactoryErp\Models\Style;

/**
 * Buyers' orders (POs): one style, a ship date, a price per piece in the
 * order's currency (minor units), and pieces per size and colour. Numbered
 * per year on making (rule factory_erp.order_number_prefix). Draft and
 * confirmed orders can change (buyers amend); in production, shipped and
 * cancelled ones cannot. Cancelling needs a reason.
 */
class Orders
{
    /** From which status an order may move to which. */
    private const MOVES = [
        'confirm' => [['draft'], 'confirmed'],
        'start' => [['confirmed'], 'in_production'],
        'ship' => [['in_production'], 'shipped'],
        'cancel' => [['draft', 'confirmed'], 'cancelled'],
    ];

    public function __construct(
        private Floor $floor,
        private Fields $fields,
        private Organizations $buyers,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** @return Collection<int, OrderLine> */
    public function lines(Organization $company, Order $order): Collection
    {
        return $this->floor->query(OrderLine::class, $company)->where('order_id', $order->getKey())->get();
    }

    /**
     * Make (order null) or change an order. Lines, when given, replace the
     * order's pieces per size and colour (a zero leaves the cell out).
     *
     * @param  array<string, mixed>  $data  Validated by OrderRequest.
     */
    public function save(Organization $company, Organization $unit, ?Order $order, array $data, User $actor): Order
    {
        if ($order === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->floor->query(Order::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        $style = $this->floor->query(Style::class, $company)->whereKey($order?->style_id ?? $data['style_id'])->first()
            ?? throw FactoryException::notFound('style');
        if ($order === null && ! $this->buyers->has($company, $style->buyer_id, Styles::BUYER_TAG)) {
            throw FactoryException::notABuyer();
        }
        $lines = isset($data['lines']) ? $this->checkedLines($style, $data['lines']) : null;
        $extra = array_key_exists('extra', $data)
            ? $this->fields->apply($company, 'order', (array) $data['extra'], $order?->extra ?? [], $order === null)
            : ($order === null ? $this->fields->apply($company, 'order', [], [], true) : null);
        $context = $this->contexts->forOrganization($unit);

        return $this->floor->transaction($company, function () use ($company, $unit, $order, $data, $style, $lines, $extra, $actor, $context) {
            if ($order !== null) {
                $order = $this->floor->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                if ($order->version !== (int) $data['base_version']) {
                    throw FactoryException::versionConflict(['version' => $order->version]);
                }
                if (! in_array($order->status, ['draft', 'confirmed'], true)) {
                    throw FactoryException::wrongStatus();
                }
                $old = $this->auditable($order);
                $order->fill(array_intersect_key($data, array_flip(['po_number', 'ship_on', 'currency', 'unit_price_minor', 'note'])));
                $order->version++;
            } else {
                $old = null;
                $order = new Order([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'buyer_id' => $style->buyer_id, 'style_id' => $style->getKey(),
                    'po_number' => $data['po_number'] ?? null, 'ship_on' => $data['ship_on'],
                    'currency' => $data['currency'] ?? (string) $this->rules->get('factory_erp.order_currency', $context),
                    'unit_price_minor' => (int) ($data['unit_price_minor'] ?? 0), 'note' => $data['note'] ?? null,
                    'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                ]);
                $order->number = $this->number($company, $context, $this->floor->today($company));
            }
            if ($extra !== null) {
                $order->extra = $extra ?: null;
            }
            $order->save();
            if ($lines !== null) {
                $this->floor->query(OrderLine::class, $company)->where('order_id', $order->getKey())->delete();
                foreach ($lines as $line) {
                    $this->floor->query(OrderLine::class, $company)->create(['organization_id' => $company->getKey(), 'order_id' => $order->getKey(), ...$line]);
                }
            }
            $this->audit->record($old === null ? 'factory_erp.order_created' : 'factory_erp.order_updated', $order, old: $old ?? [],
                new: $this->auditable($order, $lines), actor: $actor, organizationId: $company->getKey());

            return $order;
        });
    }

    /** Confirm, start, ship or cancel (with a reason). */
    public function move(Organization $company, Order $order, string $step, int $baseVersion, ?string $reason, User $actor): Order
    {
        [$from, $to] = self::MOVES[$step];

        return $this->floor->transaction($company, function () use ($company, $order, $baseVersion, $from, $to, $reason, $actor) {
            $order = $this->floor->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->version !== $baseVersion) {
                throw FactoryException::versionConflict(['version' => $order->version]);
            }
            if (! in_array($order->status, $from, true)) {
                throw FactoryException::wrongStatus();
            }
            if ($to === 'confirmed' && ! $this->floor->query(OrderLine::class, $company)->where('order_id', $order->getKey())->exists()) {
                throw FactoryException::noPieces();
            }
            $old = $order->status;
            $order->status = $to;
            if ($to === 'confirmed') {
                $order->confirmed_at = now();
            }
            if ($to === 'cancelled') {
                $order->cancelled_reason = $reason;
            }
            $order->version++;
            $order->save();
            $this->audit->record('factory_erp.order_'.$to, $order, old: ['status' => $old], new: ['status' => $to], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $order;
        });
    }

    /**
     * Pieces per size and colour, each a size and colour of the style; zeros left out.
     *
     * @param  list<array{size: string, color: string, quantity: int}>  $lines
     * @return list<array{size: string, color: string, quantity: int}>
     */
    private function checkedLines(Style $style, array $lines): array
    {
        $cells = [];
        foreach ($lines as $line) {
            foreach (['size' => 'sizes', 'color' => 'colors'] as $field => $list) {
                if (! in_array($line[$field], $style->{$list}, true)) {
                    throw FactoryException::notInStyle((string) $line[$field]);
                }
            }
            if ((int) $line['quantity'] > 0) {
                $cells["{$line['size']}|{$line['color']}"] = ['size' => $line['size'], 'color' => $line['color'], 'quantity' => (int) $line['quantity']];
            }
        }

        return array_values($cells);
    }

    private function number(Organization $company, $context, CarbonImmutable $on): string
    {
        $year = (int) $on->format('Y');
        $sequence = $this->floor->query(Sequence::class, $company)->where('kind', 'order')->where('year', $year)->lockForUpdate()->first()
            ?? new Sequence(['organization_id' => $company->getKey(), 'kind' => 'order', 'year' => $year, 'next' => 1]);
        $number = $sequence->next;
        $sequence->next = $number + 1;
        $sequence->save();

        return sprintf('%s-%d-%05d', (string) $this->rules->get('factory_erp.order_number_prefix', $context), $year, $number);
    }

    /**
     * @param  list<array{size: string, color: string, quantity: int}>|null  $lines
     * @return array<string, mixed>
     */
    private function auditable(Order $order, ?array $lines = null): array
    {
        return [
            ...$order->only(['number', 'po_number', 'currency', 'unit_price_minor', 'status']),
            'ship_on' => $order->ship_on?->toDateString(),
            ...($lines === null ? [] : ['pieces' => array_sum(array_column($lines, 'quantity'))]),
        ];
    }
}
