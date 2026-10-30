<?php

namespace Modules\Projects\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A project: where it runs (when opened), its name, client (a CRM contact,
 * or a name), template (when opened), service, manager, days and
 * description. The version seen when changing it.
 */
class ProjectRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $need = $creating ? 'required' : 'sometimes';

        return [
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'unit_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'size:26'],
            'name' => [$need, 'string', 'min:2', 'max:200'],
            'client_contact_id' => ['nullable', 'string', 'size:26'],
            'client_name' => ['nullable', 'string', 'max:190'],
            'template_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'size:26'],
            'service' => ['nullable', 'string', 'max:120'],
            'manager_id' => ['nullable', 'string', 'size:26'],
            'starts_on' => [$need, 'date_format:Y-m-d'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
