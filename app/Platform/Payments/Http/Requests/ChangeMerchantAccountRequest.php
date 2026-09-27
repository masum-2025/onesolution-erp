<?php

namespace App\Platform\Payments\Http\Requests;

use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\MerchantAccount;
use App\Platform\Support\Http\StrictFormRequest;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Validation\Rule;

/**
 * Renaming an account (applies at once), or new credentials and mode
 * (wait for approval). The gateway's fields come from the stored account;
 * whether the person may see it is checked by the controller.
 */
class ChangeMerchantAccountRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $gateway = (string) MerchantAccount::query()->withoutGlobalScope(OrganizationScope::class)
            ->whereKey((string) $this->route('account'))->value('gateway');

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'label' => ['sometimes', 'string', 'min:2', 'max:100'],
            'mode' => ['sometimes', 'required_with:credentials', Rule::in([MerchantAccount::SANDBOX, MerchantAccount::LIVE])],
            'credentials' => ['sometimes', 'array'],
            ...($this->has('credentials') ? CredentialRules::for(app(GatewayRegistry::class)->driver($gateway), true) : []),
            'current_password' => ['required', 'string', 'max:255'],
        ];
    }
}
