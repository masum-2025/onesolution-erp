<?php

namespace Modules\Education\Services;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Education\Exceptions\EducationException;

/**
 * Where an institution's records are kept: a company (or personal
 * workspace) and its campuses, in the client's database. Queries name the
 * company, so they work with or without a tenant context.
 */
class Education
{
    public function __construct(private TenantDatabases $databases, private OrganizationSettingsResolver $settings) {}

    public function companyOf(Organization $unit): Organization
    {
        if ($unit->type->isCompanyLike()) {
            return $unit;
        }
        if ($unit->type === OrganizationType::Group) {
            throw EducationException::notCompanyUnit();
        }

        return Organization::query()->whereKey($unit->ancestorIds())
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Personal->value])
            ->orderByDesc('depth')->first() ?? throw EducationException::notCompanyUnit();
    }

    /**
     * @return list<string> The unit and every unit below it.
     */
    public function subtreeIds(Organization $unit): array
    {
        return Organization::query()->subtreeOf($unit)->pluck('id')->map(fn ($id) => (string) $id)->all();
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
     * A record of the company by id, or the module's 404 naming what was asked for.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    public function find(string $model, Organization $company, ?string $id, string $what): Model
    {
        $record = $id === null ? null : $this->query($model, $company)->whereKey($id)->first();

        return $record ?? throw EducationException::notFound($what);
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

    /** The institution's country (phone numbers are read by it). */
    public function country(Organization $company): ?string
    {
        return $this->settings->values($company)['country_code'] ?? null;
    }

    /** Today at the institution, as a date. */
    public function today(Organization $company): CarbonImmutable
    {
        $timezone = ($this->settings->values($company)['timezone'] ?? null) ?: 'UTC';

        return CarbonImmutable::parse(CarbonImmutable::now()->setTimezone($timezone)->toDateString(), 'UTC');
    }
}
