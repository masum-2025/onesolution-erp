<?php

namespace Modules\Outpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Models\Chamber;
use Modules\Outpatient\Models\Schedule;

/**
 * Chambers: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class Chambers
{
    public function __construct(private OutpatientStore $store, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by ChamberRequest.
     */
    public function save(Organization $company, Organization $unit, ?Chamber $record, array $data, User $actor): Chamber
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Chamber::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor) {
            if ($record !== null) {
                $record = $this->store->query(Chamber::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['base_version']) {
                    throw OutpatientException::versionConflict(['version' => $record->version]);
                }
                $old = $record->attributesToArray();
                $record->fill(array_intersect_key($data, array_flip(['name', 'room', 'is_active'])));
                $record->version++;
            } else {
                $old = null;
                $record = new Chamber([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(['name', 'room', 'is_active'])),
                ]);
            }
            $record->save();
            $this->audit->record($old === null ? 'outpatient.chamber_created' : 'outpatient.chamber_updated', $record,
                old: $old ?? [], new: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization $company, Chamber $record, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $record, $baseVersion, $actor) {
            $record = $this->store->query(Chamber::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== $baseVersion) {
                throw OutpatientException::versionConflict(['version' => $record->version]);
            }
            if ($this->store->query(Schedule::class, $company)->where('chamber_id', $record->getKey())->exists()) {
                throw OutpatientException::inUse();
            }
            $this->audit->record('outpatient.chamber_deleted', $record, old: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /** The title a list or a link shows for a Chamber. */
    public static function title(Chamber $record): string
    {
        return (string) $record->name;
    }
}
