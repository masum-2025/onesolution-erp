<?php

namespace Modules\Fleet\Http;

use Modules\Fleet\Models\Trip;
use Modules\Fleet\Models\Vehicle;

/** The module's records as the screens read them (made by make:erp-module). */
class FleetPresenter
{
    /** @return array<string, mixed> */
    public function vehicle(Vehicle $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'registration_no' => $record->registration_no,
            'model' => $record->model,
            'kind' => $record->kind,
            'capacity_milli' => $record->capacity_milli,
            'bought_on' => $record->bought_on?->toDateString(),
            'price_minor' => $record->price_minor,
            'in_use' => $record->in_use,
            'notes' => $record->notes,
            'currency' => $record->currency,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function trip(Trip $record): array
    {
        return [
            'id' => $record->getKey(),
            'unit_id' => $record->unit_id,
            'purpose' => $record->purpose,
            'vehicle_id' => $record->vehicle_id,
            'driver_id' => $record->driver_id,
            'trip_on' => $record->trip_on?->toDateString(),
            'destination' => $record->destination,
            'distance_milli' => $record->distance_milli,
            'fuel_cost_minor' => $record->fuel_cost_minor,
            'currency' => $record->currency,
            'version' => $record->version,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }
}
