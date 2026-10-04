<?php

namespace Modules\Hrm\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Enums\EmploymentEventType;
use Modules\Hrm\Events\EmploymentChanged;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\EmploymentEvent;
use Modules\Hrm\Models\Position;

/**
 * Every change to an employment: hire, edit details, confirm, transfer,
 * promote, notice, exit, rehire. Each step writes an append-only employment
 * event, an audit entry (field names, never personal values) and, after the
 * transaction, an EmploymentChanged event for other modules. Business
 * numbers (probation, notice, kinds of employment, required details) are
 * rules of the unit or company.
 */
class EmployeeLifecycle
{
    /** Details people may edit directly (job changes go through their own steps). */
    public const EDITABLE = [
        'full_name', 'full_name_local', 'date_of_birth', 'gender', 'phone', 'email', 'address',
        'emergency_contact', 'national_id', 'tax_id', 'employment_type', 'manager_id',
    ];

    public function __construct(
        private Units $units,
        private EmployeeCodes $codes,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private TenantDatabases $databases,
        private AuditLogger $audit,
        private CurrentContext $context,
        private CustomFields $customFields,
        private ReportingLines $lines,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by HireEmployeeRequest.
     */
    public function hire(Organization $unit, array $data, User $actor): Employee
    {
        $company = $this->units->companyOf($unit);
        $joinedOn = CarbonImmutable::parse($data['joined_on'] ?? now()->toDateString());
        $position = $this->position($data['position_id'] ?? null, $company);
        $custom = $this->checkDetails($company, $unit, $data, null, requireAll: true);

        [$employee, $event] = $this->transaction($company, function () use ($unit, $company, $data, $joinedOn, $position, $actor, $custom) {
            $probationDays = (int) $this->rules->get('hrm.probation_days', $this->contexts->forOrganization($unit));

            $employee = new Employee;
            $employee->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
            $employee->forceFill([
                'organization_id' => $unit->getKey(),
                'company_id' => $company->getKey(),
                'employee_code' => $this->codes->next($company, $unit, $joinedOn),
                'position_id' => $position?->getKey(),
                'national_id_hash' => Employee::hashOf($data['national_id'] ?? null),
                'custom' => $custom === [] ? null : $custom,
                'status' => $probationDays > 0 ? EmployeeStatus::Probation : EmployeeStatus::Active,
                'joined_on' => $joinedOn,
                'probation_ends_on' => $probationDays > 0 ? $joinedOn->addDays($probationDays) : null,
                'confirmed_on' => $probationDays > 0 ? null : $joinedOn,
                'version' => 1,
            ])->save();

            $event = $this->event($employee, EmploymentEventType::Hired, $joinedOn, null, [
                'unit_id' => $unit->getKey(), 'position_id' => $position?->getKey(),
                'employment_type' => $employee->employment_type, 'employee_code' => $employee->employee_code,
            ], $data['reason'] ?? null, $actor);

            $this->audit->record('hrm.employee_hired', $employee, new: [
                'employee_code' => $employee->employee_code, 'unit_id' => $unit->getKey(),
                'position_id' => $position?->getKey(), 'employment_type' => $employee->employment_type,
                'joined_on' => $joinedOn->toDateString(),
            ], actor: $actor, organizationId: $unit->getKey());

            return [$employee, $event];
        });

        $this->announce($employee, $event);

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $data  Validated by UpdateEmployeeRequest (only EDITABLE keys).
     */
    public function update(Employee $employee, int $baseVersion, array $data, User $actor): Employee
    {
        $company = $this->companyOfEmployee($employee);
        $custom = $this->checkDetails($company, $this->unitOf($employee), $data, $employee, requireAll: false);

        return $this->transaction($company, function () use ($employee, $baseVersion, $data, $actor, $custom) {
            $employee = $this->lock($employee, $baseVersion);
            $employee->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
            if (array_key_exists('national_id', $data)) {
                $employee->national_id_hash = Employee::hashOf($data['national_id']);
            }

            $customChanged = array_key_exists('custom', $data) ? $this->changedKeys($employee->custom ?? [], $custom) : [];
            if ($customChanged !== []) {
                $employee->custom = $custom === [] ? null : $custom;
            }

            $changed = [
                ...array_values(array_diff(array_keys($employee->getDirty()), ['national_id_hash', 'custom'])),
                ...array_map(fn (string $key) => "custom.{$key}", $customChanged),
            ];
            if ($changed === []) {
                return $employee;
            }

            $employee->version = $employee->version + 1;
            $employee->save();

            $this->audit->record('hrm.employee_updated', $employee, new: ['fields' => $changed], actor: $actor, organizationId: $employee->organization_id);

            return $employee;
        });
    }

    /**
     * Link the employee to the login they use (or unlink it, null), so they
     * can check in and see their own records. The login must belong to a
     * member of the company (staff or portal) and to no other employee of it.
     */
    public function linkLogin(Employee $employee, int $baseVersion, ?string $userId, User $actor): Employee
    {
        $company = $this->companyOfEmployee($employee);
        if ($userId !== null) {
            $member = OrganizationMembership::query()->where('user_id', $userId)
                ->whereIn('organization_id', Organization::query()->subtreeOf($company)->pluck('id')->all())
                ->where('status', MembershipStatus::Active->value)->exists();
            if (! $member) {
                throw ValidationException::withMessages(['user_id' => __('hrm::hrm.validation.login_not_member')]);
            }
        }

        return $this->transaction($company, function () use ($employee, $baseVersion, $userId, $actor, $company) {
            $employee = $this->lock($employee, $baseVersion);
            if ($userId !== null && Employee::query()->where('company_id', $company->getKey())->where('user_id', $userId)
                ->whereKeyNot($employee->getKey())->where('status', '!=', EmployeeStatus::Exited->value)->exists()) {
                throw ValidationException::withMessages(['user_id' => __('hrm::hrm.validation.login_taken')]);
            }
            if ($employee->user_id === $userId) {
                return $employee;
            }

            $old = $employee->user_id;
            $employee->forceFill(['user_id' => $userId, 'version' => $employee->version + 1])->save();
            $this->audit->record('hrm.login_linked', $employee, old: ['user_id' => $old], new: ['user_id' => $userId], actor: $actor, organizationId: $employee->organization_id);

            return $employee;
        });
    }

    /**
     * @param  array{on?: string, reason?: string|null}  $data
     */
    public function confirm(Employee $employee, int $baseVersion, array $data, User $actor): Employee
    {
        return $this->step($employee, $baseVersion, EmploymentEventType::Confirmed, $data, $actor, function (Employee $employee, CarbonImmutable $on) {
            if ($employee->status !== EmployeeStatus::Probation) {
                throw HrmException::notOnProbation();
            }

            $employee->forceFill(['status' => EmployeeStatus::Active, 'confirmed_on' => $on]);

            return [['status' => EmployeeStatus::Probation->value], ['status' => EmployeeStatus::Active->value]];
        });
    }

    /**
     * @param  array{on?: string, reason?: string|null}  $data
     */
    public function transfer(Employee $employee, int $baseVersion, Organization $to, array $data, User $actor): Employee
    {
        return $this->step($employee, $baseVersion, EmploymentEventType::Transferred, $data, $actor, function (Employee $employee) use ($to) {
            $this->assertEmployed($employee);
            $this->assertSameCompany($employee, $to);
            if ($employee->organization_id === $to->getKey()) {
                throw HrmException::sameUnit();
            }

            $from = $employee->organization_id;
            $employee->organization_id = $to->getKey();

            return [['unit_id' => $from], ['unit_id' => $to->getKey()]];
        });
    }

    /**
     * @param  array{on?: string, reason?: string|null}  $data
     */
    public function promote(Employee $employee, int $baseVersion, string $positionId, ?Organization $to, array $data, User $actor): Employee
    {
        $position = $this->position($positionId, $this->companyOfEmployee($employee));

        return $this->step($employee, $baseVersion, EmploymentEventType::Promoted, $data, $actor, function (Employee $employee) use ($position, $to) {
            $this->assertEmployed($employee);
            if ($employee->position_id === $position->getKey()) {
                throw HrmException::samePosition();
            }

            $from = ['position_id' => $employee->position_id];
            $into = ['position_id' => $position->getKey()];
            $employee->position_id = $position->getKey();

            if ($to !== null && $to->getKey() !== $employee->organization_id) {
                $this->assertSameCompany($employee, $to);
                $from['unit_id'] = $employee->organization_id;
                $into['unit_id'] = $to->getKey();
                $employee->organization_id = $to->getKey();
            }

            return [$from, $into];
        });
    }

    /**
     * @param  array{on?: string, exits_on?: string|null, reason?: string|null}  $data
     */
    public function giveNotice(Employee $employee, int $baseVersion, array $data, User $actor): Employee
    {
        return $this->step($employee, $baseVersion, EmploymentEventType::NoticeGiven, $data, $actor, function (Employee $employee, CarbonImmutable $on) use ($data) {
            if (! in_array($employee->status, [EmployeeStatus::Probation, EmployeeStatus::Active], true)) {
                throw HrmException::notEmployed();
            }

            $days = (int) $this->rules->get('hrm.notice_period_days', $this->contexts->forOrganization($this->unitOf($employee)));
            $exitsOn = isset($data['exits_on']) ? CarbonImmutable::parse($data['exits_on']) : $on->addDays($days);
            $this->assertNotBeforeJoining($employee, $exitsOn);

            $from = ['status' => $employee->status->value];
            $employee->forceFill(['status' => EmployeeStatus::OnNotice, 'notice_given_on' => $on, 'exits_on' => $exitsOn]);

            return [$from, ['status' => EmployeeStatus::OnNotice->value, 'exits_on' => $exitsOn->toDateString()]];
        });
    }

    /**
     * @param  array{on?: string, reason: string}  $data  "on" is the last working day.
     */
    public function exit(Employee $employee, int $baseVersion, array $data, User $actor): Employee
    {
        return $this->step($employee, $baseVersion, EmploymentEventType::Exited, $data, $actor, function (Employee $employee, CarbonImmutable $on) use ($data) {
            $this->assertEmployed($employee);
            $this->assertNotBeforeJoining($employee, $on);

            $from = ['status' => $employee->status->value];
            $employee->forceFill(['status' => EmployeeStatus::Exited, 'exits_on' => $on, 'exit_reason' => $data['reason']]);

            return [$from, ['status' => EmployeeStatus::Exited->value]];
        });
    }

    /**
     * @param  array{on?: string, reason?: string|null, position_id?: string|null}  $data  "on" is the new joining date.
     */
    public function rehire(Employee $employee, int $baseVersion, ?Organization $to, array $data, User $actor): Employee
    {
        $position = isset($data['position_id']) ? $this->position($data['position_id'], $this->companyOfEmployee($employee)) : null;

        return $this->step($employee, $baseVersion, EmploymentEventType::Rehired, $data, $actor, function (Employee $employee, CarbonImmutable $on) use ($to, $position) {
            if ($employee->status !== EmployeeStatus::Exited) {
                throw HrmException::alreadyEmployed();
            }

            if ($to !== null && $to->getKey() !== $employee->organization_id) {
                $this->assertSameCompany($employee, $to);
                $employee->organization_id = $to->getKey();
            }
            $probationDays = (int) $this->rules->get('hrm.probation_days', $this->contexts->forOrganization($this->unitOf($employee)));

            $employee->forceFill([
                'status' => $probationDays > 0 ? EmployeeStatus::Probation : EmployeeStatus::Active,
                'joined_on' => $on,
                'probation_ends_on' => $probationDays > 0 ? $on->addDays($probationDays) : null,
                'confirmed_on' => $probationDays > 0 ? null : $on,
                'notice_given_on' => null,
                'exits_on' => null,
                'exit_reason' => null,
                'position_id' => $position?->getKey() ?? $employee->position_id,
            ]);

            return [['status' => EmployeeStatus::Exited->value], ['status' => $employee->status->value, 'unit_id' => $employee->organization_id, 'position_id' => $employee->position_id]];
        });
    }

    /**
     * One employment step under the version lock: $change edits the employee
     * and returns [from, to] for the employment event.
     *
     * @param  array{on?: string, reason?: string|null}  $data
     * @param  callable(Employee, CarbonImmutable): array{0: array<string, mixed>, 1: array<string, mixed>}  $change
     */
    private function step(Employee $employee, int $baseVersion, EmploymentEventType $type, array $data, User $actor, callable $change): Employee
    {
        $company = $this->companyOfEmployee($employee);
        $on = CarbonImmutable::parse($data['on'] ?? now()->toDateString());

        [$employee, $event] = $this->transaction($company, function () use ($employee, $baseVersion, $type, $data, $actor, $change, $on) {
            $employee = $this->lock($employee, $baseVersion);
            [$from, $to] = $change($employee, $on);

            $employee->version = $employee->version + 1;
            Employee::reassigning(fn () => $employee->save());

            $event = $this->event($employee, $type, $on, $from, $to, $data['reason'] ?? null, $actor);
            $this->audit->record('hrm.employee_'.$type->value, $employee, old: $from, new: $to, reason: $data['reason'] ?? null, actor: $actor, organizationId: $employee->organization_id);

            return [$employee, $event];
        });

        $this->announce($employee, $event);

        return $employee;
    }

    /**
     * @param  array<string, mixed>|null  $from
     * @param  array<string, mixed>|null  $to
     */
    private function event(Employee $employee, EmploymentEventType $type, CarbonImmutable $on, ?array $from, ?array $to, ?string $reason, User $actor): EmploymentEvent
    {
        return EmploymentEvent::query()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->getKey(),
            'type' => $type,
            'effective_on' => $on,
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
            'actor_user_id' => $actor->getKey(),
        ]);
    }

    private function announce(Employee $employee, EmploymentEvent $event): void
    {
        EmploymentChanged::dispatch($event->type, $employee->getKey(), $employee->organization_id, $employee->company_id, $event->effective_on->toDateString(), $event->getKey());
    }

    /**
     * The row again, locked; stale versions are refused (nobody loses work).
     */
    private function lock(Employee $employee, int $baseVersion): Employee
    {
        /** @var Employee $fresh */
        $fresh = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

        if ($fresh->version !== $baseVersion) {
            throw HrmException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    /**
     * Rules of the unit and company: kinds of employment, required details,
     * unique national id, a manager from the same company.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> The employee's extra field values after the change.
     */
    private function checkDetails(Organization $company, Organization $unit, array $data, ?Employee $employee, bool $requireAll): array
    {
        [$errors, $custom] = $this->detailErrors($company, $unit, $data, $employee, $requireAll);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $custom;
    }

    /**
     * What hiring with $data at $unit would refuse, without hiring (imports
     * check every row first). Shapes are checked by HireEmployeeRequest.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string> field => message
     */
    public function hireErrors(Organization $unit, array $data): array
    {
        $errors = [];
        try {
            $this->position($data['position_id'] ?? null, $this->units->companyOf($unit));
        } catch (ValidationException $exception) {
            $errors = array_map(fn (array $messages) => (string) $messages[0], $exception->errors());
        } catch (HrmException) {
            $errors['position_id'] = __('hrm::hrm.errors.position_not_found');
        }

        return [...$errors, ...$this->detailErrors($this->units->companyOf($unit), $unit, $data, null, true)[0]];
    }

    /** @return list<string> */
    private function changedKeys(array $old, array $new): array
    {
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);

        return array_values(array_filter($keys, fn ($key) => ($old[$key] ?? null) !== ($new[$key] ?? null)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function detailErrors(Organization $company, Organization $unit, array $data, ?Employee $employee, bool $requireAll): array
    {
        $context = $this->contexts->forOrganization($unit);
        $errors = [];

        if (array_key_exists('employment_type', $data) && ! in_array($data['employment_type'], (array) $this->rules->get('hrm.employment_types', $context), true)) {
            $errors['employment_type'] = __('hrm::hrm.validation.employment_type');
        }

        foreach ((array) $this->rules->get('hrm.required_fields', $context) as $field) {
            $given = array_key_exists($field, $data) ? $data[$field] : ($requireAll ? null : $employee?->getAttribute($field));
            if ($given === null || $given === '' || $given === []) {
                if ($requireAll || array_key_exists($field, $data)) {
                    $errors[$field] = __('hrm::hrm.validation.required_by_rule');
                }
            }
        }

        $hash = array_key_exists('national_id', $data) ? Employee::hashOf($data['national_id']) : null;
        if ($hash !== null && $this->companyEmployees($company)->where('national_id_hash', $hash)->when($employee, fn ($query) => $query->whereKeyNot($employee->getKey()))->exists()) {
            $errors['national_id'] = __('hrm::hrm.validation.duplicate_national_id');
        }

        if (! empty($data['manager_id'])) {
            $manager = $this->companyEmployees($company)->whereKey($data['manager_id'])->first();
            if ($manager === null || ! $manager->status->isEmployed() || $manager->getKey() === $employee?->getKey()) {
                $errors['manager_id'] = __('hrm::hrm.validation.manager');
            } elseif (($line = $this->lines->problem($this->companyEmployees($company), $employee, $manager, $max = (int) $this->rules->get('hrm.max_reporting_depth', $context))) !== null) {
                $errors['manager_id'] = $line['problem'] === 'loop'
                    ? __('hrm::hrm.validation.manager_loop', ['chain' => implode(' → ', $line['chain'])])
                    : __('hrm::hrm.validation.manager_too_deep', ['max' => $max]);
            }
        }

        $custom = $this->customFields->check($unit, (array) ($data['custom'] ?? []), $employee?->custom ?? [], $requireAll);

        return [[...$errors, ...$custom['errors']], $custom['values']];
    }

    /**
     * Every employee of the company, whatever unit the reader may see: for
     * existence checks only (duplicates, managers), never shown.
     */
    private function companyEmployees(Organization $company)
    {
        return Employee::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->where('company_id', $company->getKey());
    }

    private function position(?string $id, Organization $company): ?Position
    {
        if ($id === null || $id === '') {
            return null;
        }

        $position = Position::query()->find($id) ?? throw HrmException::positionNotFound();
        $positionUnit = Organization::query()->find($position->organization_id);

        if ($positionUnit === null || ! str_starts_with((string) $positionUnit->path, (string) $company->path) && ! str_starts_with((string) $company->path, (string) $positionUnit->path)) {
            throw HrmException::positionNotFound();
        }
        if (! $position->is_active) {
            throw ValidationException::withMessages(['position_id' => __('hrm::hrm.validation.position_inactive')]);
        }

        return $position;
    }

    private function assertEmployed(Employee $employee): void
    {
        if (! $employee->status->isEmployed()) {
            throw HrmException::notEmployed();
        }
    }

    private function assertSameCompany(Employee $employee, Organization $to): void
    {
        if ($this->units->companyOf($to)->getKey() !== $employee->company_id) {
            throw HrmException::otherCompany();
        }
        if (! $this->context->canWriteTo($to->getKey())) {
            throw OrganizationAccessDenied::writeOutsideScope();
        }
    }

    private function assertNotBeforeJoining(Employee $employee, CarbonImmutable $day): void
    {
        if ($day->lt($employee->joined_on)) {
            throw ValidationException::withMessages(['on' => __('hrm::hrm.validation.exit_before_joining')]);
        }
    }

    private function unitOf(Employee $employee): Organization
    {
        return Organization::query()->findOrFail($employee->organization_id);
    }

    private function companyOfEmployee(Employee $employee): Organization
    {
        return Organization::query()->findOrFail($employee->company_id);
    }

    /**
     * One transaction on the client's database and the main one (audit).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(Organization $company, callable $callback): mixed
    {
        $connection = $this->databases->forOrganization($company);

        return $connection === $this->databases->central()
            ? DB::transaction($callback)
            : DB::connection($connection)->transaction(fn () => DB::transaction($callback));
    }
}
