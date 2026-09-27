<?php

namespace App\Platform\Payments\Http\Requests;

use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\MerchantAccount;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/**
 * Connecting a gateway account: which gateway, a name, the mode, the
 * gateway's own fields (GatewayDriver::credentialFields), and the person's
 * password (it decides where customers' money goes).
 */
class ConnectMerchantAccountRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gateway' => ['required', 'string', 'max:30', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'min:2', 'max:100'],
            'mode' => ['required', Rule::in([MerchantAccount::SANDBOX, MerchantAccount::LIVE])],
            'credentials' => ['required', 'array'],
            ...CredentialRules::for(app(GatewayRegistry::class)->driver((string) $this->input('gateway')), true),
            'current_password' => ['required', 'string', 'max:255'],
        ];
    }
}
