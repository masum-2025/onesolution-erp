<?php

namespace Modules\Outpatient\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Outpatient\Models\Serial;

/**
 * Outpatient writes by route name: a doctor's week and sign-in, a new serial
 * (with an op id for retries), and moving a serial on (a reason to cancel).
 * The company and unit come from the address, never from the body.
 */
class OutpatientActionRequest extends StrictFormRequest
{
    /** A doctor's sessions in one week. */
    private const MAX_SESSIONS = 50;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'outpatient.schedules.update' => [
                'user_id' => ['nullable', 'string', 'size:26'],
                'base_version' => ['required', 'integer', 'min:1'],
                'sessions' => ['present', 'array', 'max:'.self::MAX_SESSIONS],
                'sessions.*.id' => ['nullable', 'string', 'size:26'],
                'sessions.*.chamber_id' => ['required', 'string', 'size:26'],
                'sessions.*.weekday' => ['required', 'integer', 'between:0,6'],
                'sessions.*.starts_at' => ['required', 'date_format:H:i'],
                'sessions.*.ends_at' => ['required', 'date_format:H:i'],
                'sessions.*.serial_limit' => ['nullable', 'integer', 'between:1,500'],
            ],
            'outpatient.serials.store' => [
                'schedule_id' => ['required', 'string', 'size:26'],
                'date' => ['required', 'date_format:Y-m-d'],
                'patient_id' => ['required', 'string', 'size:26'],
                'op_id' => ['nullable', 'string', 'max:64'],
            ],
            'outpatient.serials.move' => [
                'status' => ['required', 'in:'.implode(',', [Serial::ARRIVED, Serial::IN, Serial::DONE, Serial::ABSENT, Serial::CANCELLED])],
                'reason' => ['required_if:status,'.Serial::CANCELLED, 'nullable', 'string', 'min:3', 'max:300'],
                'base_version' => ['required', 'integer', 'min:1'],
            ],
            default => [],
        };
    }
}
