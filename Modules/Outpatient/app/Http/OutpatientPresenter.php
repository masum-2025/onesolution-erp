<?php

namespace Modules\Outpatient\Http;

use Modules\Outpatient\Models\Chamber;
use Modules\Outpatient\Models\Doctor;

/** The module's records as the screens read them (made by make:erp-module). */
class OutpatientPresenter
{
    /** @return array<string, mixed> */
    public function doctor(Doctor $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'name' => (object) $record->texts('name'),
            'registration_no' => $record->registration_no,
            'degrees' => $record->degrees,
            'specialty' => $record->specialty,
            'mobile' => $record->mobile,
            'fee_service_code' => $record->fee_service_code,
            'follow_up_service_code' => $record->follow_up_service_code,
            'is_active' => $record->is_active,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function chamber(Chamber $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'name' => $record->name,
            'room' => $record->room,
            'is_active' => $record->is_active,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }
}
