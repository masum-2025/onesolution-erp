<?php

namespace App\Platform\Portal\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * Inviting someone to see one record: which record, how they relate to it,
 * and the email or phone the invitation is bound to.
 */
class PortalInviteRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/'],
            'subject_id' => ['required', 'string', 'ulid'],
            // Which relations a kind allows is the module's (PortalSubjectProvider::relations).
            'relation' => ['required', 'string', 'max:20', 'regex:/^[a-z][a-z_]*$/'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'channel' => ['required', Rule::in(['mail', 'sms'])],
            'email' => ['required_if:channel,mail', 'nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['required_if:channel,sms', 'nullable', 'string', 'max:30', 'regex:/^[\d\s()+\-]+$/'],
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            // false: staff hand over the code themselves (printed, in person).
            'send' => ['sometimes', 'boolean'],
        ];
    }
}
