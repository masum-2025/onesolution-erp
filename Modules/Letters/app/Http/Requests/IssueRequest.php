<?php

namespace Modules\Letters\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\Letters\Services\Letters;

/**
 * Letters issued from a design to one employee or many (op_id: a retry
 * gives the same letters), or a preview of one (no op_id, one employee or
 * none). The inputs' values are checked against the design by Letters.
 */
class IssueRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $preview = $this->route('template') !== null;

        return [
            ...($preview ? [
                'employee_id' => ['nullable', 'string', 'size:26'],
            ] : [
                'template_id' => ['required', 'string', 'size:26'],
                'employee_ids' => ['required', 'array', 'min:1', 'max:'.Letters::MAX_PEOPLE],
                'employee_ids.*' => ['required', 'string', 'size:26', 'distinct'],
                'shared' => ['sometimes', 'boolean'],
                'op_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            ]),
            'locale' => ['required', 'in:'.implode(',', LanguageRegistry::codes())],
            'inputs' => ['sometimes', 'nullable', 'array', 'max:10'],
            'inputs.*' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
