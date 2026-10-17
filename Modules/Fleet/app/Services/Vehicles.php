<?php

namespace Modules\Fleet\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Fleet\Exceptions\FleetException;
use Modules\Fleet\Models\Trip;
use Modules\Fleet\Models\Vehicle;

/**
 * Vehicles: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class Vehicles
{
    public function __construct(private FleetStore $store, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by VehicleRequest.
     */
    public function save(Organization $company, Organization $unit, ?Vehicle $record, array $data, User $actor): Vehicle
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Vehicle::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor) {
            if ($record !== null) {
                $record = $this->store->query(Vehicle::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['base_version']) {
                    throw FleetException::versionConflict(['version' => $record->version]);
                }
                $old = $record->attributesToArray();
                $record->fill(array_intersect_key($data, array_flip(['registration_no', 'model', 'kind', 'capacity_milli', 'bought_on', 'price_minor', 'in_use', 'notes'])));
                $record->version++;
            } else {
                $old = null;
                $record = new Vehicle([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(['registration_no', 'model', 'kind', 'capacity_milli', 'bought_on', 'price_minor', 'in_use', 'notes'])),
                ]);
                $record->currency = $this->store->currency($company);
            }
            if ($record->registration_no !== null && $this->store->query(Vehicle::class, $company)->where('registration_no', $record->registration_no)
                ->when($record->exists, fn ($query) => $query->whereKeyNot($record->getKey()))->exists()) {
                throw ValidationException::withMessages(['registration_no' => __('fleet::messages.validation.taken')]);
            }
            $record->save();
            $this->audit->record($old === null ? 'fleet.vehicle_created' : 'fleet.vehicle_updated', $record,
                old: $old ?? [], new: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization $company, Vehicle $record, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $record, $baseVersion, $actor) {
            $record = $this->store->query(Vehicle::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== $baseVersion) {
                throw FleetException::versionConflict(['version' => $record->version]);
            }
            if ($this->store->query(Trip::class, $company)->where('vehicle_id', $record->getKey())->exists()) {
                throw FleetException::inUse();
            }
            $this->audit->record('fleet.vehicle_deleted', $record, old: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /** The title a list or a link shows for a Vehicle. */
    public static function title(Vehicle $record): string
    {
        return (string) $record->registration_no;
    }
}
