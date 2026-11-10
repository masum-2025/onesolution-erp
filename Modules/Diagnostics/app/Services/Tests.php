<?php

namespace Modules\Diagnostics\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Models\OrderTest;
use Modules\Diagnostics\Models\Test;
use Modules\PatientBilling\Contracts\PatientCharges;

/**
 * Tests: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class Tests
{
    public function __construct(private DiagnosticsStore $store, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by TestRequest.
     */
    public function save(Organization $company, Organization $unit, ?Test $record, array $data, User $actor): Test
    {
        if ($record === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Test::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }

        // The price comes from Patient billing: the service code must be one of the company's services in use.
        if (isset($data['service_code']) && ! array_key_exists($data['service_code'], app(PatientCharges::class)->services($company))) {
            throw ValidationException::withMessages(['service_code' => __('diagnostics::messages.validation.service_code')]);
        }

        return $this->store->transaction($company, function () use ($company, $unit, $record, $data, $actor) {
            if ($record !== null) {
                $record = $this->store->query(Test::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->version !== (int) $data['base_version']) {
                    throw DiagnosticsException::versionConflict(['version' => $record->version]);
                }
                $old = $record->attributesToArray();
                $record->fill(array_intersect_key($data, array_flip(['code', 'department', 'sample_type', 'service_code', 'turnaround_hours', 'is_active'])));
                $record->version++;
            } else {
                $old = null;
                $record = new Test([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
                    ...array_intersect_key($data, array_flip(['code', 'department', 'sample_type', 'service_code', 'turnaround_hours', 'is_active'])),
                ]);
            }
            if (array_key_exists('name', $data)) {
                $record->putTexts('name', $data['name']);
            }
            if ($record->code !== null && $this->store->query(Test::class, $company)->where('code', $record->code)
                ->when($record->exists, fn ($query) => $query->whereKeyNot($record->getKey()))->exists()) {
                throw ValidationException::withMessages(['code' => __('diagnostics::messages.validation.taken')]);
            }
            $record->save();
            $this->audit->record($old === null ? 'diagnostics.test_created' : 'diagnostics.test_updated', $record,
                old: $old ?? [], new: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());

            return $record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization $company, Test $record, int $baseVersion, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $record, $baseVersion, $actor) {
            $record = $this->store->query(Test::class, $company)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->version !== $baseVersion) {
                throw DiagnosticsException::versionConflict(['version' => $record->version]);
            }
            if ($this->store->query(OrderTest::class, $company)->where('test_id', $record->getKey())->exists()) {
                throw DiagnosticsException::inUse();
            }
            $this->audit->record('diagnostics.test_deleted', $record, old: $record->attributesToArray(), actor: $actor, organizationId: $company->getKey());
            $record->delete();
        });
    }

    /** The title a list or a link shows for a Test. */
    public static function title(Test $record): string
    {
        return $record->textIn('name');
    }
}
