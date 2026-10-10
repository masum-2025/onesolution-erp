<?php

namespace Modules\EducationFees\Services;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\EducationFees\Exceptions\FeeException;

/**
 * Where fees are kept: the institution (company) with its
 * campuses, in the client's database. Every query names the institution,
 * so it works with or without a tenant context. Days are the institution's
 * local days.
 */
class FeeOffice
{
    public function __construct(private TenantDatabases $databases, private OrganizationSettingsResolver $settings) {}

    /** The institution a unit belongs to (a group has no students of its own). */
    public function companyOf(Organization $unit): Organization
    {
        if ($unit->type->isCompanyLike()) {
            return $unit;
        }
        if ($unit->type === OrganizationType::Group) {
            throw FeeException::notCompanyUnit();
        }

        return Organization::query()->whereKey($unit->ancestorIds())
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Personal->value])
            ->orderByDesc('depth')->first() ?? throw FeeException::notCompanyUnit();
    }

    /** @return list<string> The unit and every unit below it. */
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
     * A record of the institution by id, or the module's 404 naming it.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    public function find(string $model, Organization $company, ?string $id, string $what): Model
    {
        $record = $id === null ? null : $this->query($model, $company)->whereKey($id)->first();

        return $record ?? throw FeeException::notFound($what);
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
