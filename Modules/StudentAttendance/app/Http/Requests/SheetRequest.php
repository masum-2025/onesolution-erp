<?php

namespace Modules\StudentAttendance\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\StudentAttendance\Models\Mark;

/**
 * Saving a section's sheet of a day. Who may, the day, the period and the
 * students are checked by Sheets; base_version is left out only for a
 * sheet nobody has saved yet.
 */
class SheetRequest extends StrictFormRequest
{
    /** Students one sheet may carry. */
    private const MAX_STUDENTS = 500;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period_id' => ['nullable', 'string', 'size:26'],
            'subject_id' => ['nullable', 'string', 'size:26'],
            'marks' => ['present', 'array', 'max:'.self::MAX_STUDENTS],
            'marks.*.student_id' => ['required', 'string', 'size:26', 'distinct'],
            'marks.*.status' => ['required', 'in:'.implode(',', Mark::STATUSES)],
            'marks.*.note' => ['nullable', 'string', 'max:150'],
            'op_id' => ['nullable', 'string', 'max:64'],
            'base_version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
