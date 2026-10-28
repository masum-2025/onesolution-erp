<?php

namespace Modules\Compliance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A register line: when adding, the factory and the service (and, for
 * people allowed, its official dates); then its provider, kind, result,
 * certificate and notes. The version seen when changing.
 */
class AuditRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'unit_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'service_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'provider_id' => ['nullable', 'string', 'size:26'],
            'audit_type_id' => ['nullable', 'string', 'size:26'],
            'last_audit_on' => [$creating ? 'nullable' : 'prohibited', 'date_format:Y-m-d'],
            'expires_on' => [$creating ? 'nullable' : 'prohibited', 'date_format:Y-m-d'],
            'result' => ['nullable', 'string', 'max:60'],
            'certificate_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
