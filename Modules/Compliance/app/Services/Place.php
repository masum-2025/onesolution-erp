<?php

namespace Modules\Compliance\Services;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Compliance\Exceptions\ComplianceException;

/**
 * Where compliance data lives: a factory is a company or a branch; its
 * company keeps the clock (today in its timezone); lists (services, kinds,
 * providers) are kept by a group or a company and used below it; queries run
 * in the client's own database without the request's tenant scope.
 */
class Place
{
    public function __construct(private TenantDatabases $databases, private OrganizationSettingsResolver $settings) {}

    public function companyOf(Organization $unit): Organization
    {
        if ($unit->type === OrganizationType::Company) {
            return $unit;
        }
        if (in_array($unit->type, [OrganizationType::Group, OrganizationType::Personal], true)) {
            throw ComplianceException::notFactory();
        }

        return Organization::query()->whereKey($unit->ancestorIds())->where('type', OrganizationType::Company->value)
            ->orderByDesc('depth')->first() ?? throw ComplianceException::notFactory();
    }

    /** A factory: an open company or branch. */
    public function assertFactory(Organization $unit): void
    {
        if (! in_array($unit->type, [OrganizationType::Company, OrganizationType::Branch], true) || $unit->status !== OrganizationStatus::Active) {
            throw ComplianceException::notFactory();
        }
    }

    /**
     * The unit and every unit below it.
     *
     * @return list<string>
     */
    public function subtreeIds(Organization $unit): array
    {
        return Organization::query()->subtreeOf($unit)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * The factories at the unit and below it (open companies and branches), in tree order.
     *
     * @return Collection<int, Organization>
     */
    public function factories(Organization $unit): Collection
    {
        return Organization::query()->subtreeOf($unit)->whereIn('type', [OrganizationType::Company->value, OrganizationType::Branch->value])
            ->where('status', OrganizationStatus::Active->value)->orderBy('path')->get();
    }

    /**
     * Records of a model at the given organizations, in the client's own database.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @param  list<string>  $organizationIds
     * @return Builder<T>
     */
    public function query(string $model, Organization $unit, array $organizationIds): Builder
    {
        return $model::inTenantOf($unit)->withoutGlobalScope(OrganizationScope::class)
            ->whereIn((new $model)->qualifyColumn('organization_id'), $organizationIds);
    }

    /**
     * Lists usable at a unit: kept by it or by a unit above it.
     *
     * @return list<string>
     */
    public function listOwners(Organization $unit): array
    {
        return [...$unit->ancestorIds(), $unit->getKey()];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(Organization $unit, callable $callback): mixed
    {
        $connection = $this->databases->forOrganization($unit);

        return $connection === $this->databases->central()
            ? DB::transaction($callback)
            : DB::connection($connection)->transaction(fn () => DB::transaction($callback));
    }

    /** Today in the company's timezone, as a plain date. */
    public function today(Organization $company, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $zone = ($this->settings->values($company)['timezone'] ?? null) ?: 'UTC';

        return CarbonImmutable::parse(($now ?? CarbonImmutable::now())->setTimezone($zone)->toDateString(), 'UTC');
    }
}
