<?php

namespace Modules\Hrm\Services;

use App\Models\User;
use App\Platform\Access\Models\MembershipRole;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Services\RoleService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Services\InitialPasswords;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\LoginDefaults;

/**
 * Logins for employees with the company's first password (set on the HRM
 * settings page, kept as a hash only): from the employee list, one by one or
 * many at once, and from an import. The employee signs in with their email
 * or phone, must choose their own password first (platform), then works with
 * the roles given here. Someone who already has an account keeps their own
 * password and only gets the membership (InitialPasswords).
 */
class EmployeeLogins
{
    public function __construct(
        private InitialPasswords $passwords,
        private RoleService $roles,
        private EmployeeLifecycle $lifecycle,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
        private OrganizationSettingsResolver $settings,
    ) {}

    public function defaultsOf(Organization $company): LoginDefaults
    {
        return LoginDefaults::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $company->getKey())->first()
            // Nothing set yet: version 0, saved on the company's database (UsesTenantDatabase).
            ?? (new LoginDefaults(['organization_id' => $company->getKey(), 'role_ids' => []]))->forceFill(['version' => 0]);
    }

    /**
     * What the settings page shows: whether a first password is set (never the
     * password), the roles new logins get, and the roles there are to choose.
     *
     * @return array<string, mixed>
     */
    public function settings(Organization $company): array
    {
        $defaults = $this->defaultsOf($company);
        $setBy = $defaults->password_set_by === null ? null : User::query()->find($defaults->password_set_by, ['id', 'name']);

        return [
            'password_set' => $defaults->password_hash !== null,
            'password_set_at' => $defaults->password_set_at?->toIso8601String(),
            'password_set_by' => $setBy?->name,
            'role_ids' => array_values((array) $defaults->role_ids),
            'roles' => $this->roles->usableAt($company)->get()
                ->map(fn (Role $role) => ['id' => $role->getKey(), 'name' => $role->displayName()])
                ->sortBy('name')->values()->all(),
            'days' => $this->days($company),
            'version' => (int) $defaults->version,
        ];
    }

    /**
     * @param  list<string>  $roleIds
     */
    public function saveSettings(Organization $company, ?string $password, array $roleIds, int $baseVersion, User $actor): LoginDefaults
    {
        $roleIds = array_values(array_unique($roleIds));
        if ($this->roles->usableAt($company)->whereKey($roleIds)->count() !== count($roleIds)) {
            throw ValidationException::withMessages(['role_ids' => __('hrm::hrm.validation.login_role')]);
        }

        return DB::transaction(function () use ($company, $password, $roleIds, $baseVersion, $actor) {
            $defaults = $this->defaultsOf($company);
            if ($defaults->exists) {
                $defaults = LoginDefaults::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->whereKey($defaults->getKey())->lockForUpdate()->firstOrFail();
            }
            if ((int) $defaults->version !== $baseVersion) {
                throw HrmException::versionConflict(['version' => (int) $defaults->version]);
            }

            $old = ['role_ids' => array_values((array) $defaults->role_ids)];
            $defaults->role_ids = $roleIds;
            if ($password !== null) {
                $defaults->forceFill(['password_hash' => Hash::make($password), 'password_set_by' => $actor->getKey(), 'password_set_at' => now()]);
            }
            $defaults->forceFill(['version' => (int) $defaults->version + 1])->save();

            $this->audit->record('hrm.login_defaults_changed', $defaults, old: $old, new: ['role_ids' => $roleIds, 'password_changed' => $password !== null], actor: $actor, organizationId: $company->getKey());

            return $defaults;
        });
    }

    /**
     * A login for one employee with the first password; $roleIds (else the
     * company's default roles) are added to what the person has there.
     *
     * @param  list<string>|null  $roleIds
     * @return array{login: string, employee: Employee}
     */
    public function give(Employee $employee, User $actor, ?array $roleIds = null): array
    {
        $company = Organization::query()->findOrFail($employee->company_id);
        $unit = Organization::query()->findOrFail($employee->organization_id);
        if ($employee->status === EmployeeStatus::Exited) {
            throw ValidationException::withMessages(['login' => __('hrm::hrm.validation.login_exited')]);
        }
        if ($employee->user_id !== null) {
            throw ValidationException::withMessages(['login' => __('hrm::hrm.validation.login_already')]);
        }
        $defaults = $this->defaultsOf($company);
        if ($defaults->password_hash === null) {
            throw ValidationException::withMessages(['login' => __('hrm::hrm.validation.no_first_password')]);
        }
        if ($this->contactOf($employee) === null) {
            throw ValidationException::withMessages(['login' => __('hrm::hrm.validation.login_contact_needed')]);
        }

        return DB::transaction(function () use ($employee, $actor, $roleIds, $company, $unit, $defaults) {
            $given = $this->passwords->give(
                $unit, $employee->full_name, $employee->email, $employee->phone,
                // A local number ("017…") is read in the company's country, set there or above it.
                $this->settings->explain($unit)['country_code']['value'] ?? config('tenancy.defaults.country_code'),
                $defaults->password_hash, now()->toImmutable()->addDays($this->days($unit)), $actor,
            );

            $wanted = $roleIds ?? array_values((array) $defaults->role_ids);
            if ($wanted !== [] && $given['membership']->user_id !== $actor->getKey()) {
                $current = MembershipRole::query()->where('membership_id', $given['membership']->getKey())->pluck('role_id')->all();
                if (array_diff($wanted, $current) !== []) {
                    $this->roles->syncMembershipRoles($given['membership'], [...$current, ...$wanted], $actor, 'Given with the employee\'s login');
                }
            }

            $linked = $this->lifecycle->linkLogin($employee, $employee->version, $given['user']->getKey(), $actor);

            return ['login' => $given['created'] ? 'created' : 'linked', 'employee' => $linked];
        });
    }

    /**
     * Logins for many employees at once: each is checked on its own (the
     * person's rights at that employee's unit), and a problem with one never
     * stops the others.
     *
     * @param  list<string>  $ids
     * @return list<array{id: string, name: string|null, login: string|null, error: string|null}>
     */
    public function giveMany(Organization $scope, array $ids, User $actor): array
    {
        $inside = Organization::query()->subtreeOf($scope)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $employees = Employee::query()->whereKey($ids)->whereIn('organization_id', $inside)->get()->keyBy('id');

        $results = [];
        foreach ($ids as $id) {
            $employee = $employees->get($id);
            if ($employee === null) {
                $results[] = ['id' => $id, 'name' => null, 'login' => null, 'error' => __('hrm::hrm.errors.not_found')];

                continue;
            }
            $unit = Organization::query()->findOrFail($employee->organization_id);
            if (! Gate::forUser($actor)->allows('hrm.manage', $unit) || ! Gate::forUser($actor)->allows('manageMembers', $unit)) {
                $results[] = ['id' => $id, 'name' => $employee->full_name, 'login' => null, 'error' => __('hrm::hrm.validation.login_no_right')];

                continue;
            }

            try {
                $results[] = ['id' => $id, 'name' => $employee->full_name, 'login' => $this->give($employee, $actor)['login'], 'error' => null];
            } catch (ValidationException $exception) {
                $results[] = ['id' => $id, 'name' => $employee->full_name, 'login' => null, 'error' => (string) collect($exception->errors())->flatten()->first()];
            } catch (TenancyException $exception) {
                $results[] = ['id' => $id, 'name' => $employee->full_name, 'login' => null, 'error' => $exception->userMessage()];
            }
        }

        return $results;
    }

    /** The first password again, for a login that has not chosen its own yet. */
    public function reset(Employee $employee, User $actor): Employee
    {
        $company = Organization::query()->findOrFail($employee->company_id);
        $user = $employee->user_id === null ? null : User::query()->find($employee->user_id);
        if ($user === null) {
            throw ValidationException::withMessages(['login' => __('hrm::hrm.validation.login_none')]);
        }
        $defaults = $this->defaultsOf($company);
        if ($defaults->password_hash === null) {
            throw ValidationException::withMessages(['login' => __('hrm::hrm.validation.no_first_password')]);
        }

        $this->passwords->reset($user, $company, $defaults->password_hash, now()->toImmutable()->addDays($this->days(Organization::query()->findOrFail($employee->organization_id))), $actor);

        return $employee;
    }

    /** Whether a first password is set for the company (imports ask before starting). */
    public function ready(Organization $company): bool
    {
        return $this->defaultsOf($company)->password_hash !== null;
    }

    /** The employee's login, as the list and record show it: none | pending | expired | active. */
    public function stateOf(Employee $employee): string
    {
        return InitialPasswords::state($employee->user_id === null ? null : User::query()->find($employee->user_id));
    }

    /**
     * Login states of many employees at once (one query).
     *
     * @param  iterable<Employee>  $employees
     * @return array<string, string> by employee id
     */
    public function statesOf(iterable $employees): array
    {
        $users = User::query()->whereKey(collect($employees)->pluck('user_id')->filter()->unique()->values()->all())
            ->get(['id', 'must_change_password', 'initial_password_expires_at'])->keyBy('id');

        return collect($employees)->mapWithKeys(fn (Employee $employee) => [
            $employee->getKey() => InitialPasswords::state($employee->user_id === null ? null : $users->get($employee->user_id)),
        ])->all();
    }

    private function contactOf(Employee $employee): ?string
    {
        foreach ([$employee->email, $employee->phone] as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function days(Organization $unit): int
    {
        return (int) $this->rules->get('hrm.initial_password_days', $this->contexts->forOrganization($unit));
    }
}
