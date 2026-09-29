<?php

namespace App\Platform\Offline\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * One sync: the device, its current lease, where it caught up to, and the
 * changes it made offline. Each change names the lease it was made under.
 * The size limit per sync is a rule (checked by the pipeline); this only
 * caps what a request can carry at all.
 */
class SyncRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'ulid'],
            'lease' => ['required', 'string', 'max:20000'],
            'cursor' => ['nullable', 'date'],
            'operations' => ['present', 'array', 'max:1000'],
            'operations.*' => ['array:op_id,kind,action,record_id,base_version,data,made_at,lease_id'],
            'operations.*.op_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'operations.*.kind' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/'],
            'operations.*.action' => ['required', 'string', 'max:10'],
            'operations.*.record_id' => ['nullable', 'string', 'ulid'],
            'operations.*.base_version' => ['nullable', 'integer', 'min:0'],
            'operations.*.data' => ['sometimes', 'array'],
            'operations.*.made_at' => ['required', 'date'],
            'operations.*.lease_id' => ['required', 'string', 'ulid'],
        ];
    }
}
