<?php

namespace App\Platform\PageExports\Http;

use App\Platform\PageExports\PageExports;
use App\Platform\Support\Http\StrictFormRequest;

/** One export or print of a module page: which module and page, the format, how many rows. */
class PageExportRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'module' => ['required', 'string', 'regex:/^(?:core\.)?[a-z][a-z0-9_]{1,49}$/'],
            'page' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_.:-]+$/'],
            'format' => ['required', 'string', 'in:'.implode(',', [...PageExports::FORMATS, 'print'])],
            'rows' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
