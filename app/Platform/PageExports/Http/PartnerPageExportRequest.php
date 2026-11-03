<?php

namespace App\Platform\PageExports\Http;

use App\Platform\PageExports\PageExports;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/** One export or print of a partner console page: which page, the format, how many rows. */
class PartnerPageExportRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['required', 'string', Rule::in(array_keys(PageExports::PARTNER_PAGES))],
            'format' => ['required', 'string', 'in:'.implode(',', [...PageExports::FORMATS, 'print'])],
            'rows' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
