<?php

namespace Modules\SocialCommerce\Jobs;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\SocialCommerce\Services\Publisher;

/** Puts one approved post on Facebook (ids only on the queue; Publisher claims the post first). */
class PublishPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retries are Publisher's (it knows which errors are worth another try). */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $companyId, public readonly string $postId) {}

    public function handle(Publisher $publisher): void
    {
        $company = Organization::query()->find($this->companyId);
        if ($company !== null) {
            $publisher->publish($company, $this->postId);
        }
    }
}
