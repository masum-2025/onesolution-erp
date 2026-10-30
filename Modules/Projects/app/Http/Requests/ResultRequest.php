<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * An audit's result for the connected client: official days, grade,
 * certificate number and kind, a note, and the certificate file (PDF or a
 * picture; the platform's rule caps its size when sending).
 */
class ResultRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'last_audit_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:last_audit_on'],
            'result' => ['nullable', 'string', 'max:60'],
            'certificate_no' => ['nullable', 'string', 'max:100'],
            'audit_type' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'note' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
        ];
    }
}
