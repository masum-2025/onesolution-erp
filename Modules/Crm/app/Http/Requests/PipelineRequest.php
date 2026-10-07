<?php

namespace Modules\Crm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A pipeline or one of its stages made or changed (names per language, the version seen).
 */
class PipelineRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $stage = str_contains((string) $this->route()?->uri(), 'stages');

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'name' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'name.en' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:80'],
            'is_default' => [$stage ? 'prohibited' : 'sometimes', 'boolean'],
            'is_active' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
            'probability_bp' => [$stage ? 'sometimes' : 'prohibited', 'integer', 'min:0', 'max:10000'],
            'sort_order' => [$stage ? 'sometimes' : 'prohibited', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
