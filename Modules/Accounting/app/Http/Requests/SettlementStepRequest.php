<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A step of a settlement (approve, reject, void, allocate): the version the
 * person saw; a reason to reject or void; the documents to allocate to.
 */
class SettlementStepRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = $this->route('step');

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'reason' => [in_array($step, ['reject', 'void'], true) ? 'required' : 'prohibited', 'string', 'min:5', 'max:300'],
            'allocations' => [$step === 'allocate' ? 'required' : 'prohibited', 'array', 'min:1', 'max:'.DocumentStepRequest::MAX_ALLOCATIONS],
            'allocations.*' => ['array:document_id,amount_minor'],
            'allocations.*.document_id' => ['required', 'string', 'size:26'],
            'allocations.*.amount_minor' => ['required', 'integer', 'min:1', 'max:'.JournalRequest::MAX_AMOUNT],
        ];
    }
}
