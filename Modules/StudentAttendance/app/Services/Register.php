<?php

namespace Modules\StudentAttendance\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\StudentAttendance\Exceptions\StudentAttendanceException;

/**
 * Where attendance is kept: the institution (company) with its campuses, in
 * the client's database. Every query names the institution, so it works
 * with or without a tenant context. Days are the institution's local days;
 * rules are read at the campus a section belongs to.
 */
class Register
{
    public function __construct(
        private TenantDatabases $databases,
        private OrganizationSettingsResolver $settings,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** The institution a unit belongs to (a group has no classes of its own). */
    public function companyOf(Organization $unit): Organization
    {
        if ($unit->type->isCompanyLike()) {
            return $unit;
        }
        if ($unit->type === OrganizationType::Group) {
            throw StudentAttendanceException::notCompanyUnit();
        }

        return Organization::query()->whereKey($unit->ancestorIds())
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Personal->value])
            ->orderByDesc('depth')->first() ?? throw StudentAttendanceException::notCompanyUnit();
    }

    /** @return list<string> The unit and every unit below it. */
    public function subtreeIds(Organization $unit): array
    {
        return Organization::query()->subtreeOf($unit)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * A rule's value at a campus (by id), or at the institution when the
     * campus is unknown. Read fresh every time: this service may outlive a
     * request (a route keeps its controller), so it keeps no values.
     */
    public function rule(string $key, Organization $company, ?string $unitId = null): mixed
    {
        $unit = $unitId === null ? $company : (Organization::query()->find($unitId) ?? $company);

        return $this->rules->get($key, $this->contexts->forOrganization($unit));
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return Builder<T>
     */
    public function query(string $model, Organization $company): Builder
    {
        return $model::inTenantOf($company)
            ->withoutGlobalScope(OrganizationScope::class)
            ->where((new $model)->qualifyColumn('organization_id'), $company->getKey());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(Organization $company, callable $callback): mixed
    {
        $connection = $this->databases->forOrganization($company);

        return $connection === $this->databases->central()
            ? DB::transaction($callback)
            : DB::connection($connection)->transaction(fn () => DB::transaction($callback));
    }

    /** Today at the institution, as a date. */
    public function today(Organization $company): CarbonImmutable
    {
        $timezone = ($this->settings->values($company)['timezone'] ?? null) ?: 'UTC';

        return CarbonImmutable::parse(CarbonImmutable::now()->setTimezone($timezone)->toDateString(), 'UTC');
    }
}
