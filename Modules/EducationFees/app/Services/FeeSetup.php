<?php

namespace Modules\EducationFees\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\EducationFees\Exceptions\FeeException;
use Modules\EducationFees\Models\BillLine;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Models\FeeStructure;
use Modules\EducationFees\Models\FeeStructureLine;

/**
 * What an institution charges: fee heads (what for, how often, where the
 * income goes) and fee structures (amounts per head for a session, narrowed
 * by campus, programme, class and category). A structure is drafted, then
 * made active (only one active structure per exact narrowing), and archived
 * when it no longer applies; bills keep their own amounts.
 */
class FeeSetup
{
    public function __construct(private FeeOffice $office, private ModuleRegistry $modules, private AuditLogger $audit) {}

    /** @return list<string> The income posting keys a head may name. */
    public function incomeKeys(): array
    {
        $accounts = $this->modules->get('education_fees')->ledgerAccounts;

        return array_values(array_keys(array_filter($accounts, fn (array $account) => $account['type'] === 'income')));
    }

    /** @return Collection<int, FeeHead> */
    public function heads(Organization $company): Collection
    {
        return $this->office->query(FeeHead::class, $company)->orderBy('sort_order')->orderBy('code')->get();
    }

    /** @param  array<string, mixed>  $data */
    public function saveHead(Organization $company, ?FeeHead $head, array $data, User $actor): FeeHead
    {
        if (isset($data['income_key']) && ! in_array($data['income_key'], $this->incomeKeys(), true)) {
            throw FeeException::invalidIncomeKey($data['income_key']);
        }

        return $this->office->transaction($company, function () use ($company, $head, $data, $actor) {
            $old = [];
            if ($head !== null) {
                $head = $this->office->query(FeeHead::class, $company)->whereKey($head->getKey())->lockForUpdate()->firstOrFail();
                $this->assertVersion($head->version, $data['base_version'] ?? null);
                if (isset($data['frequency']) && $data['frequency'] !== $head->frequency && $this->headUsed($company, $head)) {
                    throw FeeException::headInUse();
                }
                $old = $head->only(['code', 'frequency', 'income_key', 'tax_code_id', 'sibling_discount', 'late_fine', 'is_active']);
            }
            $head ??= new FeeHead(['organization_id' => $company->getKey(), 'version' => 0]);
            $head->fill(collect($data)->only(['code', 'frequency', 'income_key', 'tax_code_id', 'sibling_discount', 'late_fine', 'is_active', 'sort_order'])->all());
            if (isset($data['name'])) {
                $head->putTexts('name', $data['name']);
            }
            $head->version++;
            $head->save();
            $this->audit->record($old === [] ? 'education_fees.head_created' : 'education_fees.head_updated', $head, old: $old,
                new: $head->only(['code', 'frequency', 'income_key', 'tax_code_id', 'sibling_discount', 'late_fine', 'is_active']), actor: $actor, organizationId: $company->getKey());

            return $head;
        });
    }

    /**
     * A structure with its lines ({head_id, amount_minor, months?}); the
     * lines given replace the old ones (a draft or an active structure;
     * bills already made keep their amounts).
     *
     * @param  array<string, mixed>  $data
     */
    public function saveStructure(Organization $company, ?FeeStructure $structure, array $data, User $actor): FeeStructure
    {
        return $this->office->transaction($company, function () use ($company, $structure, $data, $actor) {
            if ($structure !== null) {
                $structure = $this->office->query(FeeStructure::class, $company)->whereKey($structure->getKey())->lockForUpdate()->firstOrFail();
                $this->assertVersion($structure->version, $data['base_version'] ?? null);
                if ($structure->status === 'archived') {
                    throw FeeException::wrongStatus($structure->status);
                }
            }
            $structure ??= new FeeStructure(['organization_id' => $company->getKey(), 'status' => 'draft', 'created_by' => $actor->getKey(), 'version' => 0]);
            $structure->fill(collect($data)->only(['name', 'session_id', 'unit_id', 'program_id', 'level_id', 'category_id'])->all());
            $structure->version++;
            if ($structure->status === 'active') {
                $this->assertNoOverlap($company, $structure);
            }
            $structure->save();

            if (array_key_exists('lines', $data)) {
                if ($data['lines'] === []) {
                    throw FeeException::structureEmpty();
                }
                $this->office->query(FeeStructureLine::class, $company)->where('structure_id', $structure->getKey())->delete();
                foreach ($data['lines'] as $line) {
                    (new FeeStructureLine)->fill([
                        'organization_id' => $company->getKey(), 'structure_id' => $structure->getKey(), 'head_id' => $line['head_id'],
                        'amount_minor' => (int) $line['amount_minor'], 'months' => ($line['months'] ?? []) ?: null,
                    ])->save();
                }
            }
            $this->audit->record('education_fees.structure_saved', $structure, new: [
                ...$structure->only(['name', 'session_id', 'unit_id', 'program_id', 'level_id', 'category_id', 'status']),
                'lines' => $this->lines($company, $structure)->map(fn (FeeStructureLine $line) => $line->only(['head_id', 'amount_minor', 'months']))->all(),
            ], actor: $actor, organizationId: $company->getKey());

            return $structure;
        });
    }

    /** draft -> active (it starts to bill) -> archived (it stops; bills keep their amounts). */
    public function setStructureStatus(Organization $company, FeeStructure $structure, string $status, int $baseVersion, User $actor): FeeStructure
    {
        return $this->office->transaction($company, function () use ($company, $structure, $status, $baseVersion, $actor) {
            $structure = $this->office->query(FeeStructure::class, $company)->whereKey($structure->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVersion($structure->version, $baseVersion);
            $allowed = ['active' => ['draft'], 'archived' => ['draft', 'active']][$status] ?? [];
            if (! in_array($structure->status, $allowed, true)) {
                throw FeeException::wrongStatus($structure->status);
            }
            if ($status === 'active') {
                if ($this->lines($company, $structure)->isEmpty()) {
                    throw FeeException::structureEmpty();
                }
                $structure->status = 'active';
                $this->assertNoOverlap($company, $structure);
            }
            $old = $structure->status;
            $structure->forceFill(['status' => $status, 'version' => $structure->version + 1])->save();
            $this->audit->record('education_fees.structure_'.($status === 'active' ? 'activated' : 'archived'), $structure, old: ['status' => $old], new: ['status' => $status], actor: $actor, organizationId: $company->getKey());

            return $structure;
        });
    }

    /** @return Collection<int, FeeStructureLine> */
    public function lines(Organization $company, FeeStructure $structure): Collection
    {
        return $this->office->query(FeeStructureLine::class, $company)->where('structure_id', $structure->getKey())->get();
    }

    /**
     * Active structures of a session with their lines, as FeeMath::amountsFor takes them.
     *
     * @return list<array<string, mixed>>
     */
    public function activeStructures(Organization $company, string $sessionId): array
    {
        $structures = $this->office->query(FeeStructure::class, $company)->where('session_id', $sessionId)->where('status', 'active')->orderBy('created_at')->get();
        $lines = $this->office->query(FeeStructureLine::class, $company)->whereIn('structure_id', $structures->pluck('id'))->get()->groupBy('structure_id');

        return $structures->map(fn (FeeStructure $structure) => [
            ...$structure->only(['id', 'unit_id', 'program_id', 'level_id', 'category_id']),
            'lines' => collect($lines[$structure->getKey()] ?? [])->mapWithKeys(fn (FeeStructureLine $line) => [$line->head_id => ['amount_minor' => $line->amount_minor, 'months' => $line->months]])->all(),
        ])->values()->all();
    }

    private function assertNoOverlap(Organization $company, FeeStructure $structure): void
    {
        $query = $this->office->query(FeeStructure::class, $company)->where('session_id', $structure->session_id)->where('status', 'active')
            ->when($structure->exists, fn ($query) => $query->whereKeyNot($structure->getKey()));
        foreach (['unit_id', 'program_id', 'level_id', 'category_id'] as $field) {
            $structure->{$field} === null ? $query->whereNull($field) : $query->where($field, $structure->{$field});
        }
        $other = $query->first();
        if ($other !== null) {
            throw FeeException::structureOverlaps($other->name);
        }
    }

    private function headUsed(Organization $company, FeeHead $head): bool
    {
        return $this->office->query(FeeStructureLine::class, $company)->where('head_id', $head->getKey())->exists()
            || $this->office->query(BillLine::class, $company)->where('head_id', $head->getKey())->exists();
    }

    private function assertVersion(int $current, ?int $base): void
    {
        if ($base !== null && $base !== $current) {
            throw FeeException::versionConflict(['version' => $current]);
        }
    }
}
