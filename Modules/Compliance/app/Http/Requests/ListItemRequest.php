<?php

namespace Modules\Compliance\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;

/**
 * One item of a list: an audit service or a kind of audit (a short code, its
 * name per language; a service also how many months a certificate usually
 * lasts), or a provider (name and contact). The version seen when changing.
 */
class ListItemRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $need = $creating ? 'required' : 'sometimes';
        $kind = $this->route('kind');
        $common = [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($kind === 'providers') {
            return [
                ...$common,
                'name' => [$need, 'string', 'min:2', 'max:150'],
                'email' => ['nullable', 'email', 'max:190'],
                'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{5,30}$/'],
                // The provider's business connection on the platform (CMP-2): renewal requests go over it.
                'connection_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            ];
        }

        return [
            ...$common,
            'code' => [$need, 'string', 'max:30', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'name' => [$need, 'array:'.implode(',', LanguageRegistry::codes())],
            'name.en' => [$creating ? 'required' : 'required_with:name', 'string', 'max:120'],
            'name.*' => ['nullable', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'validity_months' => [$kind === 'services' ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:120'],
        ];
    }
}
