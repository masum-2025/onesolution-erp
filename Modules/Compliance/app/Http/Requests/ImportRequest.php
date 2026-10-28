<?php

namespace Modules\Compliance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * An Excel (.xlsx) or CSV file of the register: checked only (dry_run), or
 * imported (skipping rows with problems only when asked).
 */
class ImportRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 5 MB: tens of thousands of rows; the row limit is a rule.
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt', 'extensions:xlsx,csv'],
            'dry_run' => ['sometimes', 'boolean'],
            'skip_invalid' => ['sometimes', 'boolean'],
        ];
    }
}
