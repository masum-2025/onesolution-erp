<?php

namespace Modules\Hrm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Uploading an employee document. Kinds and size limits are rules of the
 * unit; content types are checked again by EmployeeDocuments.
 */
class EmployeeDocumentRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
            'type' => ['required', 'string', 'max:30'],
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'expires_on' => ['nullable', 'date'],
        ];
    }
}
