<?php

namespace Modules\Outpatient\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Outpatient\Exceptions\OutpatientException;

/**
 * Where the module's records are kept: a company (or personal workspace) and
 * its units, in the client's database. Queries name the company, so they
 * work with or without a tenant context. Money is in the company's currency.
 */
class OutpatientStore
{
    public function __construct(private TenantDatabases $databases, private OrganizationSettingsResolver $settings) {}

    public function companyOf(Organization $unit): Organization
    {
        if (in_array($unit->type, [OrganizationType::Company, OrganizationType::Personal], true)) {
            return $unit;
        }
        if ($unit->type === OrganizationType::Group) {
            throw OutpatientException::notCompanyUnit();
        }

        return Organization::query()->whereKey($unit->ancestorIds())
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Personal->value])
            ->orderByDesc('depth')->first() ?? throw OutpatientException::notCompanyUnit();
    }

    /** @return list<string> The unit and every unit below it. */
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

    /** An outpatient rule at a unit. */
    public function rule(string $name, Organization $unit): mixed
    {
        return $this->ruleOf("outpatient.{$name}", $unit);
    }

    /** Any rule by its full key at a unit (patients.doctor_sees). */
    public function ruleOf(string $key, Organization $unit): mixed
    {
        return app(RuleResolver::class)->get($key, app(RuleContextFactory::class)->forOrganization($unit));
    }

    /** Today in the company's time zone. */
    public function today(Organization $company): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone($company))->startOfDay();
    }

    public function timezone(Organization $company): string
    {
        return $this->settings->values($company)['timezone'] ?? 'UTC';
    }

    public function currency(Organization $company): string
    {
        return $this->settings->values($company)['currency_code'] ?? throw OutpatientException::noCurrency();
    }
}
