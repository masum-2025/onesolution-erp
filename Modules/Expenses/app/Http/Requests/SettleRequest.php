<?php

namespace Modules\Expenses\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Money moving on an advance, with the version seen: paying it out (cash or
 * bank), or what is left coming back (cash, bank, or from a month's salary).
 */
class SettleRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Paying out is cash or bank; what is left may also come from a salary.
        $methods = str_ends_with((string) $this->path(), '/settle') ? 'cash,bank,payroll' : 'cash,bank';

        return [
            'base_version' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:'.$methods],
            'period' => ['required_if:method,payroll', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }
}
