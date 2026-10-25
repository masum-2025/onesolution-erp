<?php

namespace Modules\EduWebsite\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\EduWebsite\Models\Enquiry;

/** How the office handles an enquiry: its status and own note, with the version seen. */
class EnquiryRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', Enquiry::STATUSES)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
