<?php

namespace Modules\Diagnostics\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Models\Order;
use Modules\Diagnostics\Models\Referrer;

/**
 * Referring doctors: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class Referrers
{
    public function __construct(private DiagnosticsStore $store, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by ReferrerRequest.
     */
    public function save(Organization $company, Organization $unit, ?Referrer $record, array $data, User $actor): Referrer
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Referrer::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor) {
            if ($record !== null) {
                $record = $this->store->query(Referrer::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['base_version']) {
                    throw DiagnosticsException::versionConflict(['version' => $record->version]);
                }
                $old = $record->attributesToArray();
                $record->fill(array_intersect_key($data, array_flip(['name', 'registration_no', 'mobile', 'commission_percent', 'is_active'])));
                $record->version++;
            } else {
                $old = null;
                $record = new Referrer([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(['name', 'registration_no', 'mobile', 'commission_percent', 'is_active'])),
                ]);
            }
            $record->save();
            $this->audit->record($old === null ? 'diagnostics.referrer_created' : 'diagnostics.referrer_updated', $record,
                old: $old ?? [], new: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization $company, Referrer $record, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $record, $baseVersion, $actor) {
            $record = $this->store->query(Referrer::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== $baseVersion) {
                throw DiagnosticsException::versionConflict(['version' => $record->version]);
            }
            if ($this->store->query(Order::class, $company)->where('referrer_id', $record->getKey())->exists()) {
                throw DiagnosticsException::inUse();
            }
            $this->audit->record('diagnostics.referrer_deleted', $record, old: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /** The title a list or a link shows for a Referring doctor. */
    public static function title(Referrer $record): string
    {
        return (string) $record->name;
    }
}
