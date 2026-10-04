<?php

namespace Modules\Attendance\Services;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Attendance\Exceptions\AttendanceException;

/**
 * Where attendance is kept: a company (or a personal workspace) with its
 * branches and departments, in the client's database. Every query names
 * the company, so it works with or without a tenant context (requests,
 * jobs). Days are the company's local days; instants are stored in UTC.
 */
class Workplace
{
    public function __construct(
        private TenantDatabases $databases,
        private OrganizationSettingsResolver $settings,
    ) {}

    /** The company a unit belongs to (a group is not a workplace). */
    public function companyOf(Organization $unit): Organization
    {
        if (in_array($unit->type, [OrganizationType::Company, OrganizationType::Personal], true)) {
            return $unit;
        }
        if ($unit->type === OrganizationType::Group) {
            throw AttendanceException::notCompanyUnit();
        }

        return Organization::query()->whereKey($unit->ancestorIds())
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Personal->value])
            ->orderByDesc('depth')->first() ?? throw AttendanceException::notCompanyUnit();
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
     * A model's rows of the company.
     *
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

    public function timezone(Organization $company): string
    {
        return ($this->settings->values($company)['timezone'] ?? null) ?: 'UTC';
    }

    /** Today in the company's timezone, as a plain date. */
    public function today(Organization $company): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now()->setTimezone($this->timezone($company))->toDateString(), 'UTC');
    }

    /** The company's local day an instant falls on, as a plain date. */
    public function dayOf(Organization $company, CarbonImmutable $instant): CarbonImmutable
    {
        return CarbonImmutable::parse($instant->setTimezone($this->timezone($company))->toDateString(), 'UTC');
    }

    /** A local day plus minutes after its midnight, as a UTC instant. */
    public function at(Organization $company, CarbonImmutable $day, int $minutes): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString(), $this->timezone($company))->addMinutes($minutes)->utc();
    }

    /** "2026-10-14T09:05" (local time at the company) as a UTC instant. */
    public function localInstant(Organization $company, string $text): CarbonImmutable
    {
        return CarbonImmutable::parse($text, $this->timezone($company))->utc();
    }
}
