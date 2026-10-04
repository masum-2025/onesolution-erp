<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A step of a document (submit, approve, reject, void, apply): the version
 * the person saw; a reason to reject or void; what a credit is applied to.
 */
class DocumentStepRequest extends StrictFormRequest
{
    /** Most documents one step may touch. */
    public const MAX_ALLOCATIONS = 50;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $step = $this->route('step');

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'reason' => [in_array($step, ['reject', 'void'], true) ? 'required' : 'prohibited', 'string', 'min:5', 'max:300'],
            'allocations' => [$step === 'apply' ? 'required' : 'prohibited', 'array', 'min:1', 'max:'.self::MAX_ALLOCATIONS],
            'allocations.*' => ['array:document_id,amount_minor'],
            'allocations.*.document_id' => ['required', 'string', 'size:26'],
            'allocations.*.amount_minor' => ['required', 'integer', 'min:1', 'max:'.JournalRequest::MAX_AMOUNT],
        ];
    }
}
