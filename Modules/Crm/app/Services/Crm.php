<?php

namespace Modules\Crm\Services;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Crm\Exceptions\CrmException;

/**
 * Where customer relations are kept: a company (or personal workspace) and its units,
 * in the client's database. Queries name the company, so they work with or
 * without a tenant context. Money is in the company's own currency; days in its timezone.
 */
class Crm
{
    public function __construct(private TenantDatabases $databases, private OrganizationSettingsResolver $settings) {}

    public function companyOf(Organization $unit): Organization
    {
        if (in_array($unit->type, [OrganizationType::Company, OrganizationType::Personal], true)) {
            return $unit;
        }
        if ($unit->type === OrganizationType::Group) {
            throw CrmException::notCompanyUnit();
        }

        return Organization::query()->whereKey($unit->ancestorIds())
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Personal->value])
            ->orderByDesc('depth')->first() ?? throw CrmException::notCompanyUnit();
    }

    /**
     * @return list<string> The unit and every unit below it.
     */
    public function subtreeIds(Organization $unit): array
    {
        return Organization::query()->subtreeOf($unit)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
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

    public function currency(Organization $company): string
    {
        return $this->settings->values($company)['currency_code'] ?? throw CrmException::noCurrency();
    }

    /** The company's timezone (days and hours in reports are its own). */
    public function timezone(Organization $company): string
    {
        return ($this->settings->values($company)['timezone'] ?? null) ?: 'UTC';
    }

    /** The company's country (phone numbers are read by it). */
    public function country(Organization $company): ?string
    {
        return $this->settings->values($company)['country_code'] ?? null;
    }

    /** Today at the company, as a UTC date. */
    public function today(Organization $company): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now()->setTimezone($this->timezone($company))->toDateString(), 'UTC');
    }
}
