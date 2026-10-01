<?php

namespace Modules\Hrm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Hiring someone. Which kinds of employment are offered and which details
 * are required are rules of the unit (checked by EmployeeLifecycle).
 */
class HireEmployeeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function detailRules(): array
    {
        return [
            'full_name' => ['string', 'min:2', 'max:150'],
            'full_name_local' => ['nullable', 'string', 'max:150'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'in:female,male,other,undisclosed'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{5,30}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'array:line1,line2,city,district,postcode,country'],
            'address.*' => ['nullable', 'string', 'max:150'],
            'emergency_contact' => ['nullable', 'array:name,relation,phone'],
            'emergency_contact.*' => ['nullable', 'string', 'max:100'],
            'national_id' => ['nullable', 'string', 'max:40'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'employment_type' => ['string', 'max:30'],
            'manager_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = self::detailRules();
        $rules['full_name'] = ['required', ...$rules['full_name']];
        $rules['employment_type'] = ['required', ...$rules['employment_type']];

        return [
            ...$rules,
            // The unit they work in (the route's organization when left out).
            'organization_id' => ['nullable', 'string', 'size:26'],
            'position_id' => ['nullable', 'string', 'size:26'],
            'joined_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
