<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Point a posting key at one of the company's accounts. base_version is the
 * mapping's version when one exists (none for the first time).
 */
class PostingAccountRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'string', 'size:26'],
            'base_version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
