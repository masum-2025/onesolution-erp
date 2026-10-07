<?php

namespace Modules\Crm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A deal made, changed, or moved to another stage (the version seen).
 */
class DealRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = $this->route('step');
        if ($step !== null) {
            return [
                'base_version' => ['required', 'integer', 'min:1'],
                'stage_id' => ['required', 'string', 'size:26'],
                'lost_reason' => ['nullable', 'string', 'min:3', 'max:300'],
            ];
        }
        $creating = $this->isMethod('post');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'unit_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:26'],
            'contact_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'pipeline_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'string', 'size:26'],
            'stage_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'string', 'size:26'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'value_minor' => ['sometimes', 'integer', 'min:0', 'max:999999999999999'],
            'expected_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'extra' => ['sometimes', 'array', 'max:40'],
        ];
    }
}
