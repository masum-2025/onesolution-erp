<?php

namespace Modules\Inventory\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Inventory\Models\Item;

/**
 * A unit, category, warehouse or item, created (POST) or changed with the
 * version seen (PATCH). The kind comes from the address.
 */
class CatalogRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $locales = (array) config('tenancy.supported_locales');
        $common = [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'name' => [$required, 'array:'.implode(',', $locales)],
            'name.en' => [$required, 'string', 'min:1', 'max:120'],
            'name.*' => ['nullable', 'string', 'max:120'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
        $code = [$required, 'string', 'regex:/^[A-Za-z0-9.\-_]{1,20}$/'];

        return match ($this->kind()) {
            'units' => [...$common, 'code' => [$required, 'string', 'regex:/^[A-Za-z0-9.\-_]{1,12}$/'], 'decimals' => [$required, 'integer', 'min:0', 'max:3']],
            'categories' => [...$common, 'code' => $code, 'parent_id' => ['sometimes', 'nullable', 'string', 'max:26']],
            'warehouses' => [...$common, 'code' => $code, 'unit_id' => [$required, 'string', 'max:26']],
            default => [
                ...$common,
                'sku' => [$required, 'string', 'regex:/^[A-Za-z0-9.\-_\/]{1,40}$/'],
                'barcode' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9\-]{4,64}$/'],
                'category_id' => ['sometimes', 'nullable', 'string', 'max:26'],
                'unit_id' => [$required, 'string', 'max:26'],
                'kind' => [$required, 'in:'.implode(',', Item::KINDS)],
                'track_batches' => ['sometimes', 'boolean'],
                'sale_price_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
                'tax_code_id' => ['sometimes', 'nullable', 'string', 'max:26'],
                'reorder_level_milli' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
                'reorder_quantity_milli' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
                'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
        };
    }

    public function kind(): string
    {
        return explode('/', trim((string) preg_replace('#^.*/inventory/#', '', $this->path()), '/'))[0];
    }
}
