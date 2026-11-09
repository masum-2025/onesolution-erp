<?php

namespace Modules\SocialCommerce\Services;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;

/**
 * Where pages and posts are kept: a company (or personal workspace), in the
 * client's database. Queries name the company, so they work with or
 * without a tenant context (scheduled publishing, Meta's callbacks).
 */
class SocialBook
{
    public function __construct(private TenantDatabases $databases, private OrganizationSettingsResolver $settings) {}

    public function companyOf(Organization $unit): Organization
    {
        if (in_array($unit->type, [OrganizationType::Company, OrganizationType::Personal], true)) {
            return $unit;
        }
        if ($unit->type === OrganizationType::Group) {
            throw SocialCommerceException::notCompanyUnit();
        }

        return Organization::query()->whereKey($unit->ancestorIds())
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Personal->value])
            ->orderByDesc('depth')->first() ?? throw SocialCommerceException::notCompanyUnit();
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

    /**
     * Runs the callback once in every client database (the shared one first), for work across companies.
     *
     * @param  callable(): void  $callback
     */
    public function everyDatabase(callable $callback): void
    {
        foreach ([null, ...$this->databases->names()] as $database) {
            $this->databases->onConnection($this->databases->connectionFor($database), $callback);
        }
    }

    public function currency(Organization $company): string
    {
        return (string) ($this->settings->values($company)['currency_code'] ?? '');
    }

    /** The company's timezone (days in reports are its own). */
    public function timezone(Organization $company): string
    {
        return (string) (($this->settings->values($company)['timezone'] ?? null) ?: 'UTC');
    }
}
