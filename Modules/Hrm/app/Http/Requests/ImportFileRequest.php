<?php

namespace Modules\Hrm\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * A CSV of employees to check. Its size and number of rows are rules
 * (hrm.import_max_kb, hrm.import_max_rows), checked by EmployeeImporter;
 * 10 MB is the ceiling no rule can raise.
 */
class ImportFileRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,txt', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            // The unit new employees join unless a row names another (unit_code).
            'unit_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => __('hrm::hrm.validation.import_file'),
            'file.mimetypes' => __('hrm::hrm.validation.import_file'),
        ];
    }
}
