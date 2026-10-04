<?php

namespace Modules\Accounting\Console;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\TaxCodes;

/**
 * Give every company that already keeps books the tax codes of its country's
 * tax profile it does not have yet (run on deploy of ACC-4a, and after a
 * profile file gains a code). Codes a company changed stay as they are.
 */
class SeedTaxCodes extends Command
{
    protected $signature = 'accounting:seed-tax-codes';

    protected $description = 'Add missing tax codes of each company\'s country tax profile';

    public function handle(TenantDatabases $databases, TaxCodes $codes): int
    {
        $companies = [];
        foreach ([null, ...$databases->names()] as $database) {
            $databases->onConnection($databases->connectionFor($database), function () use (&$companies) {
                Account::query()->withoutGlobalScope(OrganizationScope::class)->distinct()->pluck('organization_id')
                    ->each(function ($id) use (&$companies) {
                        $companies[(string) $id] = true;
                    });
            });
        }

        $added = 0;
        foreach (Organization::query()->whereKey(array_keys($companies))->get() as $company) {
            $added += $codes->seed($company);
        }
        $this->info("Added {$added} tax code(s).");

        return self::SUCCESS;
    }
}
