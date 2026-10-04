<?php

namespace Modules\Attendance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Asking to fix a day (in and/or out, local times, a reason), or deciding
 * on it (approve; reject with an optional note) with the version seen.
 * employee_id only when HR asks for someone else.
 */
class CorrectionRequest extends StrictFormRequest
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
                'note' => [$step === 'reject' ? 'nullable' : 'prohibited', 'string', 'max:500'],
            ];
        }

        return [
            'employee_id' => ['sometimes', 'string', 'max:26'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'in_at' => ['nullable', 'required_without:out_at', 'date_format:Y-m-d\TH:i'],
            'out_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
