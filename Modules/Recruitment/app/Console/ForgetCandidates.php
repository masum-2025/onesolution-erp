<?php

namespace Modules\Recruitment\Console;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Services\Applications;

/**
 * Forgets people: applications never confirmed, and those rejected or
 * withdrawn longer ago than each company's rule
 * (recruitment.keep_rejected_days), with their files, interviews and
 * feedback. Runs in every client database. Privacy work: it runs even
 * where the module was turned off since.
 */
class ForgetCandidates extends Command
{
    protected $signature = 'recruitment:forget-candidates';

    protected $description = 'Forget job applicants never confirmed or not taken, after the time each company keeps them';

    public function handle(TenantDatabases $databases, Applications $applications): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $companies = $databases->onConnection($databases->connectionFor($database), fn () => Application::query()
                ->withoutGlobalScope(OrganizationScope::class)->distinct()->pluck('organization_id')->all());
            foreach (Organization::query()->whereKey($companies)->get() as $company) {
                $count += $applications->forget($company);
            }
        }
        $this->info("Forgot {$count} application(s).");

        return self::SUCCESS;
    }
}
