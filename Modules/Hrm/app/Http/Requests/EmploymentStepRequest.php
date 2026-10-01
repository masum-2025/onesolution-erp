<?php

namespace Modules\Hrm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * One employment step: confirm, transfer, promote, notice, exit, rehire
 * (the {step} in the address decides which fields are needed).
 */
class EmploymentStepRequest extends StrictFormRequest
{
    public const STEPS = ['confirm', 'transfer', 'promote', 'notice', 'exit', 'rehire'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = (string) $this->route('step');

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'on' => ['nullable', 'date'],
            'reason' => [$step === 'exit' ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
            'to_organization_id' => [$step === 'transfer' ? 'required' : 'nullable', 'string', 'size:26'],
            'position_id' => [$step === 'promote' ? 'required' : 'nullable', 'string', 'size:26'],
            'exits_on' => ['nullable', 'date'],
        ];
    }
}
