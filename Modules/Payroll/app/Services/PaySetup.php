<?php

namespace Modules\Payroll\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Component;
use Modules\Payroll\Models\Structure;
use Modules\Payroll\Models\StructureItem;

/**
 * A company's pay make-up: components (earnings and deductions) and
 * structures (which components, each a fixed amount or a share of the
 * basic). Every change is audited; codes are unique in the company.
 */
class PaySetup
{
    public function __construct(private Payrolls $payrolls, private AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: array<string, string>, kind: string, taxable?: bool, prorated?: bool, sort?: int}  $data
     */
    public function createComponent(Organization $company, array $data, User $actor): Component
    {
        return $this->payrolls->transaction($company, function () use ($company, $data, $actor) {
            $this->assertFree(Component::class, $company, $data['code'], null);
            $component = new Component;
            $component->fill([
                'organization_id' => $company->getKey(), 'code' => $data['code'], 'kind' => $data['kind'],
                'taxable' => $data['taxable'] ?? $data['kind'] === 'earning', 'prorated' => $data['prorated'] ?? $data['kind'] === 'earning',
                'sort' => $data['sort'] ?? 0, 'is_active' => true, 'version' => 1,
            ]);
            $component->putTexts('name', $data['name']);
            $component->save();
            $this->audit->record('payroll.component_created', $component, new: $component->only(['code', 'kind', 'taxable', 'prorated']), actor: $actor, organizationId: $company->getKey());

            return $component;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateComponent(Organization $company, Component $component, int $baseVersion, array $data, User $actor): Component
    {
        return $this->payrolls->transaction($company, function () use ($company, $component, $baseVersion, $data, $actor) {
            /** @var Component $component */
            $component = $this->locked(Component::class, $company, $component->getKey(), $baseVersion);
            if (isset($data['code'])) {
                $this->assertFree(Component::class, $company, $data['code'], $component->getKey());
            }
            $old = $component->only(['code', 'taxable', 'prorated', 'is_active']);
            $component->fill(array_intersect_key($data, array_flip(['code', 'taxable', 'prorated', 'sort', 'is_active'])));
            if (isset($data['name'])) {
                $component->putTexts('name', $data['name']);
            }
            $component->version++;
            $component->save();
            $this->audit->record('payroll.component_updated', $component, old: $old, new: $component->only(['code', 'taxable', 'prorated', 'is_active']), actor: $actor, organizationId: $company->getKey());

            return $component;
        });
    }

    /**
     * @param  array{code: string, name: array<string, string>, items: list<array{component_id: string, calc: string, amount_minor?: int|null, rate_bp?: int|null}>}  $data
     */
    public function createStructure(Organization $company, array $data, User $actor): Structure
    {
        $items = $this->checkItems($company, $data['items']);

        return $this->payrolls->transaction($company, function () use ($company, $data, $items, $actor) {
            $this->assertFree(Structure::class, $company, $data['code'], null);
            $structure = new Structure;
            $structure->fill(['organization_id' => $company->getKey(), 'code' => $data['code'], 'is_active' => true, 'version' => 1]);
            $structure->putTexts('name', $data['name']);
            $structure->save();
            $this->writeItems($company, $structure, $items);
            $this->audit->record('payroll.structure_created', $structure, new: ['code' => $structure->code, 'items' => $items], actor: $actor, organizationId: $company->getKey());

            return $structure;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateStructure(Organization $company, Structure $structure, int $baseVersion, array $data, User $actor): Structure
    {
        $items = isset($data['items']) ? $this->checkItems($company, $data['items']) : null;

        return $this->payrolls->transaction($company, function () use ($company, $structure, $baseVersion, $data, $items, $actor) {
            /** @var Structure $structure */
            $structure = $this->locked(Structure::class, $company, $structure->getKey(), $baseVersion);
            if (isset($data['code'])) {
                $this->assertFree(Structure::class, $company, $data['code'], $structure->getKey());
            }
            $old = ['code' => $structure->code, 'items' => $this->items($company, $structure)->map(fn (StructureItem $item) => $item->only(['component_id', 'calc', 'amount_minor', 'rate_bp']))->all()];
            $structure->fill(array_intersect_key($data, array_flip(['code', 'is_active'])));
            if (isset($data['name'])) {
                $structure->putTexts('name', $data['name']);
            }
            if ($items !== null) {
                $this->payrolls->query(StructureItem::class, $company)->where('structure_id', $structure->getKey())->delete();
                $this->writeItems($company, $structure, $items);
            }
            $structure->version++;
            $structure->save();
            $this->audit->record('payroll.structure_updated', $structure, old: $old, new: ['code' => $structure->code, 'items' => $items], actor: $actor, organizationId: $company->getKey());

            return $structure;
        });
    }

    /**
     * @return Collection<int, StructureItem>
     */
    public function items(Organization $company, Structure $structure): Collection
    {
        return $this->payrolls->query(StructureItem::class, $company)->where('structure_id', $structure->getKey())->orderBy('sort')->get();
    }

    /**
     * Items as written: active components of the company, each once, a
     * fixed amount or basis points of the basic.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{component_id: string, calc: string, amount_minor: int|null, rate_bp: int|null}>
     */
    private function checkItems(Organization $company, array $items): array
    {
        $components = $this->payrolls->query(Component::class, $company)->whereKey(array_column($items, 'component_id'))->where('is_active', true)->pluck('id')->all();
        $errors = [];
        $checked = [];
        $seen = [];
        foreach (array_values($items) as $index => $item) {
            if (! in_array($item['component_id'] ?? null, $components, true) || isset($seen[$item['component_id']])) {
                $errors["items.{$index}.component_id"] = __('payroll::payroll.validation.component');
            }
            $seen[$item['component_id'] ?? ''] = true;
            $fixed = ($item['calc'] ?? '') === 'fixed';
            $value = $fixed ? ($item['amount_minor'] ?? null) : ($item['rate_bp'] ?? null);
            if ($value === null) {
                $errors["items.{$index}.".($fixed ? 'amount_minor' : 'rate_bp')] = __('payroll::payroll.validation.item_value');
            }
            $checked[] = [
                'component_id' => (string) ($item['component_id'] ?? ''), 'calc' => $fixed ? 'fixed' : 'percent_of_basic',
                'amount_minor' => $fixed ? (int) $value : null, 'rate_bp' => $fixed ? null : (int) $value,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $checked;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeItems(Organization $company, Structure $structure, array $items): void
    {
        foreach ($items as $sort => $item) {
            $row = new StructureItem;
            $row->fill([...$item, 'organization_id' => $company->getKey(), 'structure_id' => $structure->getKey(), 'sort' => $sort])->save();
        }
    }

    /**
     * @param  class-string<Component|Structure>  $model
     */
    private function assertFree(string $model, Organization $company, string $code, ?string $except): void
    {
        $taken = $this->payrolls->query($model, $company)->where('code', $code)->when($except !== null, fn ($query) => $query->whereKeyNot($except))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => __('payroll::payroll.validation.code_taken')]);
        }
    }

    /**
     * @param  class-string<Component|Structure>  $model
     */
    private function locked(string $model, Organization $company, string $id, int $baseVersion): Component|Structure
    {
        $record = $this->payrolls->query($model, $company)->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($record->version !== $baseVersion) {
            throw PayrollException::versionConflict(['version' => $record->version]);
        }

        return $record;
    }
}
