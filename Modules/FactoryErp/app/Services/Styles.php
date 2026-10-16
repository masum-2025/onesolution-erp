<?php

namespace Modules\FactoryErp\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Services\Organizations;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\BomLine;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\OrderLine;
use Modules\FactoryErp\Models\Style;
use Modules\Inventory\Services\Stock;

/**
 * A buyer's styles and what each is made of. The buyer is a CRM
 * organization contact tagged "buyer". Sizes and colours keep their order;
 * one still used by an order cannot be taken out. Consumption is written
 * per piece or per dozen, fixed for the style when it is made (rule
 * factory_erp.consumption_basis). The bill of materials is replaced as a
 * whole, every line an active Inventory item.
 */
class Styles
{
    public const BUYER_TAG = 'buyer';

    public function __construct(
        private Floor $floor,
        private Fields $fields,
        private Organizations $buyers,
        private Stock $stock,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** @return Collection<int, BomLine> */
    public function bom(Organization $company, Style $style): Collection
    {
        return $this->floor->query(BomLine::class, $company)->where('style_id', $style->getKey())->orderBy('sort_order')->orderBy('created_at')->get();
    }

    /**
     * Make (style null) or change a style.
     *
     * @param  array<string, mixed>  $data  Validated by StyleRequest.
     */
    public function save(Organization $company, ?Style $style, array $data, User $actor): Style
    {
        if (isset($data['buyer_id']) && ! $this->buyers->has($company, $data['buyer_id'], self::BUYER_TAG)) {
            throw FactoryException::notABuyer();
        }
        foreach (['sizes', 'colors'] as $list) {
            if (isset($data[$list])) {
                $data[$list] = self::clean($data[$list]);
                if ($data[$list] === []) {
                    throw ValidationException::withMessages([$list => __('factory_erp::factory.validation.list_empty')]);
                }
            }
        }
        // Finished pieces go into the store as an Inventory item that keeps stock.
        if (($data['finished_item_id'] ?? null) !== null) {
            $item = $this->stock->items($company, [$data['finished_item_id']])[0] ?? null;
            if ($item === null || $item['kind'] !== 'stock') {
                throw FactoryException::notAnItem('finished_item_id');
            }
        }
        $extra = array_key_exists('extra', $data)
            ? $this->fields->apply($company, 'style', (array) $data['extra'], $style?->extra ?? [], $style === null)
            : ($style === null ? $this->fields->apply($company, 'style', [], [], true) : null);

        return $this->floor->transaction($company, function () use ($company, $style, $data, $extra, $actor) {
            if ($style !== null) {
                $style = $this->floor->query(Style::class, $company)->whereKey($style->getKey())->lockForUpdate()->firstOrFail();
                if ($style->version !== (int) $data['base_version']) {
                    throw FactoryException::versionConflict(['version' => $style->version]);
                }
                // A size or colour an order still uses stays.
                foreach (['sizes' => 'size', 'colors' => 'color'] as $list => $column) {
                    if (! isset($data[$list])) {
                        continue;
                    }
                    $gone = array_values(array_diff($style->{$list}, $data[$list]));
                    if ($gone !== [] && $this->floor->query(OrderLine::class, $company)->whereIn('order_id', $this->floor->query(Order::class, $company)->where('style_id', $style->getKey())->select('id'))
                        ->whereIn($column, $gone)->where('quantity', '>', 0)->exists()) {
                        throw FactoryException::inUse($column);
                    }
                }
                $old = $style->only(['buyer_id', 'code', 'name', 'product_type', 'sizes', 'colors', 'is_active']);
                $style->fill(array_intersect_key($data, array_flip(['buyer_id', 'code', 'name', 'product_type', 'sizes', 'colors', 'finished_item_id', 'note', 'is_active'])));
                $style->version++;
            } else {
                $old = null;
                $style = new Style([
                    'organization_id' => $company->getKey(), 'buyer_id' => $data['buyer_id'], 'code' => $data['code'], 'name' => $data['name'],
                    'product_type' => $data['product_type'] ?? null, 'sizes' => $data['sizes'], 'colors' => $data['colors'], 'note' => $data['note'] ?? null,
                    'finished_item_id' => $data['finished_item_id'] ?? null,
                    'consumption_basis' => (string) $this->rules->get('factory_erp.consumption_basis', $this->contexts->forOrganization($company)),
                ]);
            }
            if ($extra !== null) {
                $style->extra = $extra ?: null;
            }
            if ($this->floor->query(Style::class, $company)->where('code', $style->code)->when($style->exists, fn ($query) => $query->whereKeyNot($style->getKey()))->exists()) {
                throw ValidationException::withMessages(['code' => __('factory_erp::factory.validation.code_taken')]);
            }
            $style->save();
            $this->audit->record($old === null ? 'factory_erp.style_created' : 'factory_erp.style_updated', $style, old: $old ?? [],
                new: $style->only(['buyer_id', 'code', 'name', 'product_type', 'sizes', 'colors', 'finished_item_id', 'is_active']), actor: $actor, organizationId: $company->getKey());

            return $style;
        });
    }

    /**
     * Replace a style's bill of materials.
     *
     * @param  list<array{item_id: string, consumption_milli: int, wastage_bp?: int|null, size?: ?string, color?: ?string, note?: ?string}>  $lines
     */
    public function saveBom(Organization $company, Style $style, int $baseVersion, array $lines, User $actor): Style
    {
        $items = collect($this->stock->items($company, array_values(array_unique(array_column($lines, 'item_id')))))->keyBy('id');
        $wastage = (int) $this->rules->get('factory_erp.default_wastage_bp', $this->contexts->forOrganization($company));
        foreach ($lines as $index => $line) {
            if (! $items->has($line['item_id'])) {
                throw FactoryException::notAnItem();
            }
            foreach (['size' => 'sizes', 'color' => 'colors'] as $field => $list) {
                if (($line[$field] ?? null) !== null && ! in_array($line[$field], $style->{$list}, true)) {
                    throw FactoryException::notInStyle((string) $line[$field]);
                }
            }
        }

        return $this->floor->transaction($company, function () use ($company, $style, $baseVersion, $lines, $wastage, $actor) {
            $style = $this->floor->query(Style::class, $company)->whereKey($style->getKey())->lockForUpdate()->firstOrFail();
            if ($style->version !== $baseVersion) {
                throw FactoryException::versionConflict(['version' => $style->version]);
            }
            $this->floor->query(BomLine::class, $company)->where('style_id', $style->getKey())->delete();
            foreach (array_values($lines) as $order => $line) {
                $this->floor->query(BomLine::class, $company)->create([
                    'organization_id' => $company->getKey(), 'style_id' => $style->getKey(), 'item_id' => $line['item_id'],
                    'consumption_milli' => (int) $line['consumption_milli'], 'wastage_bp' => (int) ($line['wastage_bp'] ?? $wastage),
                    'size' => $line['size'] ?? null, 'color' => $line['color'] ?? null, 'note' => $line['note'] ?? null, 'sort_order' => $order,
                ]);
            }
            $style->version++;
            $style->save();
            $this->audit->record('factory_erp.bom_saved', $style, new: ['lines' => count($lines)], actor: $actor, organizationId: $company->getKey());

            return $style;
        });
    }

    /**
     * Trimmed, unique, non-empty, in the order given.
     *
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private static function clean(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($value) => trim((string) $value), $values), fn (string $value) => $value !== '')));
    }
}
