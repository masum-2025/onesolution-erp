<?php

namespace Modules\Leave\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Leave\Exceptions\LeaveException;
use Modules\Leave\Models\LeaveType;

/**
 * A company's kinds of leave. The usual ones come from the rule
 * leave.default_types (per country: Bangladesh's Labour Act values are
 * placeholders for a legal adviser) and are then the company's own to
 * change. A type is switched off, never deleted. Changes are audited.
 */
class LeaveTypes
{
    /** Fields a type takes from the API or the default list (amounts in half days). */
    public const FIELDS = [
        'paid', 'accrual', 'allowance_halves', 'worked_days_per_day', 'carry_max_halves', 'half_day', 'counts_days_off',
        'document_after_days', 'gender', 'encashable', 'is_active', 'sort_order',
    ];

    public function __construct(
        private Place $place,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  code, name (texts by locale) and FIELDS; base_version when changing.
     */
    public function save(Organization $company, ?LeaveType $type, array $data, User $actor): LeaveType
    {
        return $this->place->transaction($company, function () use ($company, $type, $data, $actor) {
            if ($type !== null) {
                /** @var LeaveType $type */
                $type = $this->place->query(LeaveType::class, $company)->whereKey($type->getKey())->lockForUpdate()->firstOrFail();
                if ($type->version !== (int) ($data['base_version'] ?? 0)) {
                    throw LeaveException::versionConflict(['version' => $type->version]);
                }
            }
            $code = strtoupper((string) ($data['code'] ?? $type?->code));
            $taken = $this->place->query(LeaveType::class, $company)->where('code', $code)
                ->when($type !== null, fn ($query) => $query->whereKeyNot($type->getKey()))->exists();
            if ($taken) {
                throw LeaveException::codeTaken();
            }

            $old = $type === null ? null : $this->values($type);
            $type ??= new LeaveType(['organization_id' => $company->getKey(), 'version' => 0]);
            $type->fill(['code' => $code, ...array_intersect_key($data, array_flip(self::FIELDS)), 'version' => $type->version + 1]);
            if ($type->accrual !== 'worked_days') {
                $type->worked_days_per_day = null;
            }
            if (array_key_exists('name', $data)) {
                $type->putTexts('name', $data['name']);
            }
            $type->save();
            $this->audit->record($old === null ? 'leave.type_created' : 'leave.type_changed', $type, old: $old ?? [], new: $this->values($type), actor: $actor, organizationId: $company->getKey());

            return $type;
        });
    }

    /**
     * Add the usual types (rule leave.default_types at the company) that the
     * company does not have yet, by code. Returns how many were added.
     */
    public function addDefaults(Organization $company, User $actor): int
    {
        $defaults = (array) $this->rules->get('leave.default_types', $this->contexts->forOrganization($company));
        $added = 0;
        foreach ($defaults as $index => $default) {
            $code = strtoupper((string) ($default['code'] ?? ''));
            if ($code === '' || $this->place->query(LeaveType::class, $company)->where('code', $code)->exists()) {
                continue;
            }
            $this->save($company, null, [
                'code' => $code,
                'name' => (array) ($default['name'] ?? ['en' => $code]),
                'paid' => (bool) ($default['paid'] ?? true),
                'accrual' => in_array($default['accrual'] ?? null, LeaveType::ACCRUALS, true) ? $default['accrual'] : 'yearly',
                'allowance_halves' => 2 * (int) ($default['days'] ?? 0),
                'worked_days_per_day' => isset($default['worked_days_per_day']) ? (int) $default['worked_days_per_day'] : null,
                'carry_max_halves' => 2 * (int) ($default['carry_max_days'] ?? 0),
                'half_day' => (bool) ($default['half_day'] ?? true),
                'counts_days_off' => (bool) ($default['counts_days_off'] ?? false),
                'document_after_days' => isset($default['document_after_days']) ? (int) $default['document_after_days'] : null,
                'gender' => $default['gender'] ?? null,
                'encashable' => (bool) ($default['encashable'] ?? false),
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
            ], $actor);
            $added++;
        }

        return $added;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(LeaveType $type): array
    {
        return ['code' => $type->code, 'name' => $type->texts('name'), ...array_intersect_key($type->only(self::FIELDS), array_flip(self::FIELDS))];
    }
}
