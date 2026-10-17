<?php

namespace Modules\Fleet\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Modules\Fleet\Exceptions\FleetException;
use Modules\Fleet\Models\Trip;
use Modules\Fleet\Models\Vehicle;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Trips: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class Trips
{
    public function __construct(private FleetStore $store, private AuditLogger $audit, private EmployeeDirectory $employees) {}

    /**
     * @param  array<string, mixed>  $data  Validated by TripRequest.
     */
    public function save(Organization $company, Organization $unit, ?Trip $record, array $data, User $actor): Trip
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Trip::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        if (($data['vehicle_id'] ?? null) !== null
            && ! $this->store->query(Vehicle::class, $company)->whereKey($data['vehicle_id'])->whereIn('unit_id', $this->store->subtreeIds($unit))->exists()) {
            throw FleetException::badLink('vehicle_id');
        }
        if (($data['driver_id'] ?? null) !== null) {
            $employee = $this->employees->find($company, $data['driver_id']);
            if ($employee === null || ! in_array($employee->unitId, $this->store->subtreeIds($unit), true)) {
                throw FleetException::badLink('driver_id');
            }
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor) {
            if ($record !== null) {
                $record = $this->store->query(Trip::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['base_version']) {
                    throw FleetException::versionConflict(['version' => $record->version]);
                }
                $old = $record->attributesToArray();
                $record->fill(array_intersect_key($data, array_flip(['purpose', 'vehicle_id', 'driver_id', 'trip_on', 'destination', 'distance_milli', 'fuel_cost_minor'])));
                $record->version++;
            } else {
                $old = null;
                $record = new Trip([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(['purpose', 'vehicle_id', 'driver_id', 'trip_on', 'destination', 'distance_milli', 'fuel_cost_minor'])),
                ]);
                $record->currency = $this->store->currency($company);
            }
            $record->save();
            $this->audit->record($old === null ? 'fleet.trip_created' : 'fleet.trip_updated', $record,
                old: $old ?? [], new: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization $company, Trip $record, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $record, $baseVersion, $actor) {
            $record = $this->store->query(Trip::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== $baseVersion) {
                throw FleetException::versionConflict(['version' => $record->version]);
            }
            $this->audit->record('fleet.trip_deleted', $record, old: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /** The title a list or a link shows for a Trip. */
    public static function title(Trip $record): string
    {
        return (string) $record->purpose;
    }
}
