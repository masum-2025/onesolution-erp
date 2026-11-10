<?php

namespace Modules\Pharmacy\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Modules\Pharmacy\Exceptions\PharmacyException;
use Modules\Pharmacy\Models\Manufacturer;

/**
 * Manufacturers: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class Manufacturers
{
    public function __construct(private PharmacyStore $store, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by ManufacturerRequest.
     */
    public function save(Organization $company, Organization $unit, ?Manufacturer $record, array $data, User $actor): Manufacturer
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Manufacturer::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor) {
            if ($record !== null) {
                $record = $this->store->query(Manufacturer::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['base_version']) {
                    throw PharmacyException::versionConflict(['version' => $record->version]);
                }
                $old = $record->attributesToArray();
                $record->fill(array_intersect_key($data, array_flip(['country', 'in_use'])));
                $record->version++;
            } else {
                $old = null;
                $record = new Manufacturer([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(['country', 'in_use'])),
                ]);
            }
            if (array_key_exists('name', $data)) {
                $record->putTexts('name', $data['name']);
            }
            $record->save();
            $this->audit->record($old === null ? 'pharmacy.manufacturer_created' : 'pharmacy.manufacturer_updated', $record,
                old: $old ?? [], new: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization $company, Manufacturer $record, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $record, $baseVersion, $actor) {
            $record = $this->store->query(Manufacturer::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== $baseVersion) {
                throw PharmacyException::versionConflict(['version' => $record->version]);
            }
            $this->audit->record('pharmacy.manufacturer_deleted', $record, old: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /** The title a list or a link shows for a Manufacturer. */
    public static function title(Manufacturer $record): string
    {
        return $record->textIn('name');
    }
}
