<?php

namespace Modules\Inpatient\Http;

use Modules\Inpatient\Models\Ward;

/** The module's records as the screens read them (made by make:erp-module). */
class InpatientPresenter
{
    /** @return array<string, mixed> */
    public function ward(Ward $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'name' => (object) $record->texts('name'),
            'kind' => $record->kind,
            'is_active' => $record->is_active,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }
}
