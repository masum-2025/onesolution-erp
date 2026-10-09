<?php

namespace App\Platform\Access\Http\Requests;

use App\Platform\Access\PermissionCatalog;
use App\Platform\Localization\LanguageRegistry;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for role names, descriptions and permission lists.
 * Names are translatable: {"en": "...", "bn": "..."}; at least one is needed.
 */
trait RoleAttributeRules
{
    /**
     * @return array<string, mixed>
     */
    protected function roleRules(bool $creating): array
    {
        $locales = implode(',', LanguageRegistry::codes());

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'array:'.$locales],
            'name.*' => ['nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'array:'.$locales],
            'description.*' => ['nullable', 'string', 'max:300'],
            'permissions' => ['sometimes', 'array', 'max:500'],
            'permissions.*' => ['string', 'distinct', Rule::in(app(PermissionCatalog::class)->keys())],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                // A new role starts from a permission list (may be empty) or a template.
                if ($this->isMethod('post') && ! $this->has('permissions') && ! $this->filled('template_key')) {
                    $validator->errors()->add('permissions', __('access.validation.permissions_or_template'));
                }
            },
            function (Validator $validator) {
                if (! $this->has('name') || ! is_array($this->input('name'))) {
                    return;
                }

                $filled = array_filter($this->input('name'), fn ($text) => is_string($text) && trim($text) !== '');

                if ($filled === []) {
                    $validator->errors()->add('name', __('access.validation.name_required'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['permissions.*.in' => __('access.validation.unknown_permission')];
    }
}
