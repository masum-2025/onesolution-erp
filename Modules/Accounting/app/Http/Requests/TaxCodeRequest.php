<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Accounting\Models\TaxCode;

/**
 * A tax code: code, name in every supported language, rate in basis points
 * (15% = 1500), kind and side.
 */
class TaxCodeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $locales = (array) config('tenancy.supported_locales');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'regex:/^[A-Za-z0-9.\-_]{1,20}$/'],
            'name' => [$creating ? 'required' : 'sometimes', 'array:'.implode(',', $locales)],
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'rate_bp' => [$creating ? 'required' : 'sometimes', 'integer', 'min:0', 'max:10000'],
            'kind' => [$creating ? 'required' : 'sometimes', 'string', 'in:'.implode(',', TaxCode::KINDS)],
            'applies_to' => [$creating ? 'required' : 'sometimes', 'string', 'in:'.implode(',', TaxCode::SIDES)],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }
}
