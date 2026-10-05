<?php

namespace Modules\Pos\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\TaxCodes;
use Modules\Inventory\Services\Stock;
use Modules\Pos\Exceptions\PosException;
use Modules\Pos\Models\Register;

/**
 * Counters (tills) of a company: created and changed with the version seen
 * (pos.manage), switched off rather than removed, audited. A counter sells
 * out of one of the company's warehouses (Inventory) at a branch; codes are
 * unique in the company. The catalogue is what a counter sells: active
 * items with a price, their tax rate (Accounting's codes, where kept) and
 * how many are on hand.
 */
class Counters
{
    public function __construct(private Tills $tills, private Stock $stock, private ModuleResolver $modules, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Organization $company, ?Register $register, ?int $baseVersion, array $data, User $actor): Register
    {
        $this->check($company, $register, $data);

        return $this->tills->transaction($company, function () use ($company, $register, $baseVersion, $data, $actor) {
            $fields = ['code', 'unit_id', 'warehouse_id', 'payment_methods', 'is_active'];
            if ($register === null) {
                $register = new Register;
                $register->fill(['organization_id' => $company->getKey(), 'is_active' => true, 'version' => 1, 'payment_methods' => ['cash']]);
                $old = null;
            } else {
                $register = $this->tills->query(Register::class, $company)->whereKey($register->getKey())->lockForUpdate()->firstOrFail();
                if ($register->version !== $baseVersion) {
                    throw PosException::versionConflict(['version' => $register->version]);
                }
                $old = $register->only($fields);
                $register->version++;
            }
            $register->fill(array_intersect_key($data, array_flip($fields)));
            if (isset($data['name'])) {
                $register->putTexts('name', $data['name']);
            }
            $register->save();
            $this->audit->record($old === null ? 'pos.register_created' : 'pos.register_updated', $register, old: $old ?? [], new: $register->only($fields), actor: $actor, organizationId: $company->getKey());

            return $register;
        });
    }

    /**
     * What the counter sells, priced, with tax rates and stock on hand.
     *
     * @return array{items: list<array<string, mixed>>, units: array<string, array<string, mixed>>}
     */
    public function catalogue(Organization $company, Register $register): array
    {
        $items = array_values(array_filter($this->stock->items($company, null, null, 5000), fn (array $item) => $item['sale_price_minor'] !== null));
        $codes = array_values(array_unique(array_filter(array_column($items, 'tax_code_id'))));
        $rates = $codes === [] || ! $this->modules->isEnabled('accounting', $company) ? [] : app(TaxCodes::class)->salesRates($company, $codes);
        $onHand = $this->stock->onHand($company, $register->warehouse_id, array_column($items, 'id'));

        return [
            'items' => array_map(fn (array $item) => [
                ...$item, 'tax_rate_bp' => $rates[$item['tax_code_id'] ?? ''] ?? 0,
                'on_hand_milli' => $item['kind'] === 'stock' ? ($onHand[$item['id']] ?? 0) : null,
            ], $items),
            'units' => $this->stock->units($company),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function check(Organization $company, ?Register $register, array $data): void
    {
        $errors = [];
        if (isset($data['code']) && $this->tills->query(Register::class, $company)->where('code', $data['code'])
            ->when($register !== null, fn ($query) => $query->whereKeyNot($register->getKey()))->exists()) {
            $errors['code'] = __('pos::pos.validation.code_taken');
        }
        if (isset($data['unit_id']) && ! in_array($data['unit_id'], $this->tills->subtreeIds($company), true)) {
            $errors['unit_id'] = __('pos::pos.validation.unit');
        }
        if (isset($data['warehouse_id'])) {
            $warehouse = $this->stock->warehouse($company, $data['warehouse_id']);
            if ($warehouse === null || ! $warehouse['is_active']) {
                $errors['warehouse_id'] = __('pos::pos.validation.warehouse');
            }
        }
        if (isset($data['payment_methods']) && array_diff($data['payment_methods'], Register::METHODS) !== []) {
            $errors['payment_methods'] = __('pos::pos.validation.methods');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
