<?php

namespace Modules\Inpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Modules\Inpatient\Exceptions\InpatientException;
use Modules\Inpatient\Models\Bed;
use Modules\Inpatient\Models\Ward;

/**
 * Wards and cabins: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class Wards
{
    public function __construct(private InpatientStore $store, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by WardRequest.
     */
    public function save(Organization $company, Organization $unit, ?Ward $record, array $data, User $actor): Ward
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Ward::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor) {
            if ($record !== null) {
                $record = $this->store->query(Ward::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['base_version']) {
                    throw InpatientException::versionConflict(['version' => $record->version]);
                }
                $old = $record->attributesToArray();
                $record->fill(array_intersect_key($data, array_flip(['kind', 'is_active'])));
                $record->version++;
            } else {
                $old = null;
                $record = new Ward([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(['kind', 'is_active'])),
                ]);
            }
            if (array_key_exists('name', $data)) {
                $record->putTexts('name', $data['name']);
            }
            $record->save();
            $this->audit->record($old === null ? 'inpatient.ward_created' : 'inpatient.ward_updated', $record,
                old: $old ?? [], new: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization $company, Ward $record, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $record, $baseVersion, $actor) {
            $record = $this->store->query(Ward::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== $baseVersion) {
                throw InpatientException::versionConflict(['version' => $record->version]);
            }
            if ($this->store->query(Bed::class, $company)->where('ward_id', $record->getKey())->exists()) {
                throw InpatientException::inUse();
            }
            $this->audit->record('inpatient.ward_deleted', $record, old: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /** The title a list or a link shows for a Ward or cabin. */
    public static function title(Ward $record): string
    {
        return $record->textIn('name');
    }
}
