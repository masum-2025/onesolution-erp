<?php

namespace Modules\EducationFees\Jobs;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\EducationFees\Services\FeePostings;

/**
 * Posts an institution's waiting fee events to its books. One at a time per
 * institution (unique); a failure stays on its row for
 * education-fees:post-pending, the job itself never fails the counter.
 */
class PostFees implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $companyId) {}

    public function uniqueId(): string
    {
        return $this->companyId;
    }

    public function handle(FeePostings $postings): void
    {
        $company = Organization::query()->find($this->companyId);
        if ($company !== null) {
            $postings->run($company);
        }
    }
}
