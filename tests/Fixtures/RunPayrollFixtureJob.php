<?php

namespace Tests\Fixtures;

use App\Platform\Modules\Jobs\EnsureModuleEnabledForJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stand-in for a scheduled payroll job; records which organizations it ran for.
 */
class RunPayrollFixtureJob implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    public static array $ranFor = [];

    public function __construct(public string $organizationId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new EnsureModuleEnabledForJob('payroll', $this->organizationId)];
    }

    public function handle(): void
    {
        self::$ranFor[] = $this->organizationId;
    }
}
