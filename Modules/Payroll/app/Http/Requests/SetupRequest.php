<?php

namespace Modules\Payroll\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\Payroll\Models\Component;
use Modules\Payroll\Models\StructureItem;

/**
 * A component (code, name, kind, taxable, prorated) or a structure (code,
 * name, items: a component with a fixed amount or basis points of the
 * basic), created (POST) or changed with the version seen (PATCH).
 */
class SetupRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $locales = LanguageRegistry::codes();
        $common = [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'code' => [$required, 'string', 'regex:/^[A-Za-z0-9.\-_]{1,20}$/'],
            'name' => [$required, 'array:'.implode(',', $locales)],
            'name.en' => [$required, 'string', 'min:2', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];

        if ($this->routeIs('*.structures.*') || str_contains($this->path(), '/structures')) {
            return [
                ...$common,
                'items' => [$creating ? 'present' : 'sometimes', 'array', 'max:30'],
                'items.*' => ['array:component_id,calc,amount_minor,rate_bp'],
                'items.*.component_id' => ['required', 'string', 'max:26'],
                'items.*.calc' => ['required', 'in:'.implode(',', StructureItem::CALCS)],
                'items.*.amount_minor' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
                'items.*.rate_bp' => ['nullable', 'integer', 'min:0', 'max:100000'],
            ];
        }

        return [
            ...$common,
            'kind' => [$creating ? 'required' : 'prohibited', 'in:'.implode(',', Component::KINDS)],
            'taxable' => ['sometimes', 'boolean'],
            'prorated' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
