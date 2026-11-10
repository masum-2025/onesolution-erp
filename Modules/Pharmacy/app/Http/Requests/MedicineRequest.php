<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Pharmacy\Models\Medicine;

/**
 * Making or changing a medicine: its item (brand name, SKU, barcode, selling
 * price of one piece, VAT code) and what makes it a medicine (generic,
 * strength, form, DGDA no., MRP of one piece, packs). An op id when made, its version when changed.
 */
class MedicineRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $need = $creating ? 'required' : 'sometimes';

        return [
            'brand_name' => [$need, 'array:en,bn'],
            'brand_name.en' => ['required_with:brand_name', 'string', 'max:150'],
            'brand_name.bn' => ['nullable', 'string', 'max:150'],
            'sku' => [$need, 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'generic_id' => [$need, 'string', 'size:26'],
            'manufacturer_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'strength' => ['sometimes', 'nullable', 'string', 'max:40'],
            'form' => [$need, 'in:'.implode(',', Medicine::FORMS)],
            'dgda_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'mrp_minor' => [$need, 'integer', 'min:1', 'max:100000000000'],
            'sale_price_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'tax_code_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'pieces_per_strip' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'strips_per_box' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'requires_prescription' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
