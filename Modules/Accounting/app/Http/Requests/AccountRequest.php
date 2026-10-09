<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Modules\Accounting\Enums\AccountStatus;
use Modules\Accounting\Enums\AccountType;

/**
 * An account of the chart, named in every language the platform supports.
 * A sub-account takes its group's type; a top-level account names one.
 */
class AccountRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $locales = LanguageRegistry::codes();

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/'],
            'name' => [$creating ? 'required' : 'sometimes', 'array:'.implode(',', $locales)],
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'name.*' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'in:'.implode(',', array_column(AccountType::cases(), 'value'))],
            'parent_id' => ['nullable', 'string', 'size:26'],
            'is_group' => ['sometimes', 'boolean'],
            'status' => [$creating ? 'prohibited' : 'sometimes', 'string', 'in:'.implode(',', array_column(AccountStatus::cases(), 'value'))],
        ];
    }
}
