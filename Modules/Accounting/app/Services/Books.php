<?php

namespace Modules\Accounting\Services;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;

/**
 * A company's books: who keeps them (the company or a personal workspace,
 * never a group, branch or department), where they live (the client's own
 * database) and in which currency. Queries name the company explicitly, so
 * the same code serves requests and system postings (no tenant context).
 * Callers authorize the company first.
 */
class Books
{
    public function __construct(
        private TenantDatabases $databases,
        private OrganizationSettingsResolver $settings,
    ) {}

    /** The organization in the address must keep books itself. */
    public function assertKeepsBooks(Organization $organization): Organization
    {
        if (! in_array($organization->type, [OrganizationType::Company, OrganizationType::Personal], true)) {
            throw AccountingException::notCompany();
        }

        return $organization;
    }

    /**
     * Records of one model in the company's books.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public function query(string $model, Organization $company): Builder
    {
        return $model::inTenantOf($company)
            ->withoutGlobalScope(OrganizationScope::class)
            ->where((new $model)->qualifyColumn('organization_id'), $company->getKey());
    }

    /** A plain table of the books (rows without a model, e.g. number sequences). */
    public function table(string $table, Organization $company): QueryBuilder
    {
        return DB::connection($this->databases->forOrganization($company))->table($table);
    }

    /**
     * A journal's lines in order (named by company, so it works without a tenant context).
     *
     * @return Builder<JournalLine>
     */
    public function linesOf(Organization $company, Journal $journal): Builder
    {
        return $this->query(JournalLine::class, $company)->where('journal_id', $journal->getKey())->orderBy('line_no');
    }

    public function isSetUp(Organization $company): bool
    {
        return $this->query(Account::class, $company)->exists();
    }

    public function assertSetUp(Organization $company): void
    {
        if (! $this->isSetUp($company)) {
            throw AccountingException::notSetUp();
        }
    }

    /** The currency the books are kept in: the company's own (inherited) currency. */
    public function currency(Organization $company): string
    {
        return $this->settings->values($company)['currency_code'] ?? throw AccountingException::noCurrency();
    }

    /** Today in the company's timezone, as a plain date (compared as dates, never as instants). */
    public function today(Organization $company): CarbonImmutable
    {
        $zone = $this->settings->values($company)['timezone'] ?? 'UTC';

        return CarbonImmutable::parse(CarbonImmutable::now()->setTimezone($zone ?: 'UTC')->toDateString(), 'UTC');
    }

    /**
     * The cost centre of a line: the company itself, or one of its branches or departments.
     *
     * @return string|null Null when the id is not inside the company.
     */
    public function costCentre(Organization $company, ?string $id): ?string
    {
        if ($id === null || $id === $company->getKey()) {
            return $company->getKey();
        }

        return Organization::query()->subtreeOf($company)->whereKey($id)->exists() ? $id : null;
    }

    /**
     * A cost centre and everything below it (for reports).
     *
     * @return list<string>
     */
    public function costCentreTree(Organization $company, string $id): array
    {
        $unit = Organization::query()->subtreeOf($company)->whereKey($id)->first()
            ?? throw ValidationException::withMessages(['cost_centre_id' => __('accounting::accounting.validation.cost_centre_outside')]);

        return Organization::query()->subtreeOf($unit)->pluck('id')->map(fn ($key) => (string) $key)->all();
    }

    /**
     * One transaction on the client's database and the main one (audit).
     *
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
}
