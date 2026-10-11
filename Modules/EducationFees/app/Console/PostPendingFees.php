<?php

namespace Modules\EducationFees\Console;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\EducationFees\Models\Posting;
use Modules\EducationFees\Services\FeePostings;

/**
 * Every hour: fee events that have not reached the books yet (the queue
 * was down, a period was closed, an account was missing) are tried again.
 */
class PostPendingFees extends Command
{
    protected $signature = 'education-fees:post-pending';

    protected $description = 'Post fee bills, receipts and other money events still waiting for the books.';

    public function handle(TenantDatabases $databases, FeePostings $postings): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $databases->onConnection($databases->connectionFor($database), function () use ($postings, &$count) {
                $companies = Posting::query()->withoutGlobalScope(OrganizationScope::class)->whereIn('status', ['pending', 'failed'])->distinct()->pluck('organization_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    $count += $postings->run($company, retryFailed: true);
                }
            });
        }
        $this->info("Posted {$count} fee event(s).");

        return self::SUCCESS;
    }
}
