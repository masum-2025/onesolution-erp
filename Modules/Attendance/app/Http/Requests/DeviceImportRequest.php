<?php

namespace Modules\Attendance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Attendance\Models\DeviceFormat;

/**
 * An attendance machine's file (CSV) and which columns hold the employee
 * code and the time (one column, or a date and a time column).
 */
class DeviceImportRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $column = ['nullable', 'string', 'max:100'];

        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,txt', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            'columns' => ['required', 'array:code,datetime,date,time'],
            'columns.code' => ['required', 'string', 'max:100'],
            'columns.datetime' => [...$column, 'required_without_all:columns.date,columns.time', 'prohibits:columns.date,columns.time'],
            'columns.date' => [...$column, 'required_with:columns.time'],
            'columns.time' => [...$column, 'required_with:columns.date'],
            'datetime_format' => ['required', 'string', 'in:'.implode(',', DeviceFormat::FORMATS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => __('attendance::attendance.device.file_type'),
            'file.mimetypes' => __('attendance::attendance.device.file_type'),
            'columns.datetime.required_without_all' => __('attendance::attendance.device.time_columns'),
        ];
    }
}
