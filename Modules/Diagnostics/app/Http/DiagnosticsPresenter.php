<?php

namespace Modules\Diagnostics\Http;

use Modules\Diagnostics\Models\Referrer;
use Modules\Diagnostics\Models\Test;

/** The module's records as the screens read them (made by make:erp-module). */
class DiagnosticsPresenter
{
    /** @return array<string, mixed> */
    public function test(Test $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'code' => $record->code,
            'name' => (object) $record->texts('name'),
            'department' => $record->department,
            'sample_type' => $record->sample_type,
            'service_code' => $record->service_code,
            'turnaround_hours' => $record->turnaround_hours,
            'is_active' => $record->is_active,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function referrer(Referrer $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'name' => $record->name,
            'registration_no' => $record->registration_no,
            'mobile' => $record->mobile,
            'commission_percent' => $record->commission_percent,
            'is_active' => $record->is_active,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }
}
