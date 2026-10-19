<?php

namespace Modules\Leave\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Asking for leave: the type, the first and last day (half days at either
 * end), a reason, and an op_id so a repeated send is the same request.
 * employee_id only when HR asks on someone's behalf.
 */
class AskLeaveRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'string', 'max:26'],
            'type_id' => ['required', 'string', 'max:26'],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before_or_equal:'.$this->latest()],
            'from_half' => ['sometimes', 'boolean'],
            'to_half' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'op_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }

    /** At most a year from the first day (a request lies inside one leave year anyway). */
    private function latest(): string
    {
        $from = (string) $this->input('from_date');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? date('Y-m-d', strtotime($from.' +366 days')) : '9999-12-31';
    }
}
