<?php

namespace Modules\StudentAttendance\Http;

use Modules\StudentAttendance\Models\Holiday;
use Modules\StudentAttendance\Models\Period;
use Modules\StudentAttendance\Models\Sheet;

/** What the attendance API sends: sheets, periods and holidays as plain arrays. */
class AttendancePresenter
{
    /** @return array<string, mixed> */
    public function sheet(Sheet $sheet): array
    {
        return [
            'id' => $sheet->getKey(),
            'section_id' => $sheet->section_id,
            'date' => $sheet->taken_on->toDateString(),
            'slot' => $sheet->slot,
            'period_id' => $sheet->period_id,
            'subject_id' => $sheet->subject_id,
            'teacher_id' => $sheet->teacher_id,
            'taken_by' => $sheet->taken_by,
            'counts' => ['present' => $sheet->present_count, 'absent' => $sheet->absent_count, 'late' => $sheet->late_count, 'leave' => $sheet->leave_count],
            'version' => $sheet->version,
            'updated_at' => $sheet->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function period(Period $period): array
    {
        return [
            'id' => $period->getKey(), 'unit_id' => $period->unit_id, 'number' => $period->number, 'name' => $period->texts('name'),
            'starts_at' => $period->starts_at, 'ends_at' => $period->ends_at, 'is_active' => $period->is_active, 'version' => $period->version,
        ];
    }

    /** @return array<string, mixed> */
    public function holiday(Holiday $holiday): array
    {
        return [
            'id' => $holiday->getKey(), 'unit_id' => $holiday->unit_id, 'starts_on' => $holiday->starts_on->toDateString(),
            'ends_on' => $holiday->ends_on->toDateString(), 'name' => $holiday->texts('name'), 'level_ids' => $holiday->level_ids,
        ];
    }
}
