<?php

namespace Modules\Accounting\Console;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\ChartOfAccounts;

/**
 * After a release that adds posting keys (e.g. receivable and payable),
 * give every company that already keeps books the accounts its chart
 * template names for them. Safe to run again; choices people made stay.
 */
class MapPostingAccounts extends Command
{
    protected $signature = 'accounting:map-postings';

    protected $description = 'Map posting keys added since a company set up its books to its template accounts';

    public function handle(TenantDatabases $databases, ChartOfAccounts $chart): int
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

        $count = 0;
        foreach (Organization::query()->whereKey(array_keys($companies))->get() as $company) {
            $keys = $chart->mapMissingPostings($company);
            if ($keys !== []) {
                $this->line($company->displayName().': '.implode(', ', $keys));
                $count += count($keys);
            }
        }
        $this->info("Mapped {$count} posting account(s).");

        return self::SUCCESS;
    }
}
