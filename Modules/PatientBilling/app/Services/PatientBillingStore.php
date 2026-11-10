<?php

namespace Modules\PatientBilling\Services;

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
use Modules\PatientBilling\Exceptions\PatientBillingException;
use Modules\PatientBilling\Models\Sequence;

/**
 * Where the module's records are kept: a company (or personal workspace) and
 * its units, in the client's database. Queries name the company, so they
 * work with or without a tenant context. Money is in the company's currency.
 */
class PatientBillingStore
{
    public function __construct(private TenantDatabases $databases, private OrganizationSettingsResolver $settings) {}

    public function companyOf(Organization $unit): Organization
    {
        if (in_array($unit->type, [OrganizationType::Company, OrganizationType::Personal], true)) {
            return $unit;
        }
        if ($unit->type === OrganizationType::Group) {
            throw PatientBillingException::notCompanyUnit();
        }

        return Organization::query()->whereKey($unit->ancestorIds())
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Personal->value])
            ->orderByDesc('depth')->first() ?? throw PatientBillingException::notCompanyUnit();
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

    /** A patient_billing rule at a unit. */
    public function rule(string $name, Organization $unit): mixed
    {
        return app(RuleResolver::class)->get("patient_billing.{$name}", app(RuleContextFactory::class)->forOrganization($unit));
    }

    /** Now, and the start of today in the company's time zone. */
    public function today(Organization $company): CarbonImmutable
    {
        return CarbonImmutable::now($this->settings->values($company)['timezone'] ?? 'UTC')->startOfDay();
    }

    /** The next number of a kind ("bill", "receipt", "refund") with the company's prefix: "B000001". Call inside a transaction. */
    public function nextNumber(Organization $company, string $kind): string
    {
        $sequence = $this->query(Sequence::class, $company)->where('kind', $kind)->lockForUpdate()->first()
            ?? new Sequence(['organization_id' => $company->getKey(), 'kind' => $kind, 'last_number' => 0]);
        $sequence->last_number++;
        $sequence->save();
        $prefixes = (array) $this->rule('number_prefixes', $company);
        $prefix = $prefixes[$kind] ?? ['bill' => 'B', 'receipt' => 'MR', 'refund' => 'RF'][$kind];

        return strtoupper((string) $prefix).str_pad((string) $sequence->last_number, 6, '0', STR_PAD_LEFT);
    }

    public function currency(Organization $company): string
    {
        return $this->settings->values($company)['currency_code'] ?? throw PatientBillingException::noCurrency();
    }
}
