<?php

namespace Modules\Recruitment\Console;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\Recruitment\Models\Offer;
use Modules\Recruitment\Services\Offers;

/**
 * Job offers not answered by their day (in each company's own day) expire:
 * their link stops working. Runs in every client database.
 */
class ExpireOffers extends Command
{
    protected $signature = 'recruitment:expire-offers';

    protected $description = 'Expire job offers not answered by their day';

    public function handle(TenantDatabases $databases, Offers $offers): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $companies = $databases->onConnection($databases->connectionFor($database), fn () => Offer::query()
                ->withoutGlobalScope(OrganizationScope::class)->where('status', Offer::SENT)->distinct()->pluck('organization_id')->all());
            foreach (Organization::query()->whereKey($companies)->get() as $company) {
                $count += $offers->expire($company);
            }
        }
        $this->info("Expired {$count} offer(s).");

        return self::SUCCESS;
    }
}
