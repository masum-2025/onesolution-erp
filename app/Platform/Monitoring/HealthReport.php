<?php

namespace App\Platform\Monitoring;

use App\Platform\Monitoring\Models\SecurityAlert;
use App\Platform\Tenancy\Databases\PlacementStatus;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Databases\TenantPlacement;
use App\Platform\Tenancy\Databases\TenantPlacements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * How the platform is doing (Phase 9-2), for monitoring tools and the
 * operators: counts and times only, never tenant data. Each check is ok,
 * warn or fail; the report takes the worst.
 */
class HealthReport
{
    public const HEARTBEAT = 'monitoring:scheduler_heartbeat';

    private const RANK = ['ok' => 0, 'warn' => 1, 'fail' => 2];

    public function __construct(
        private Metrics $metrics,
        private TenantDatabases $databases,
        private TenantPlacements $placements,
    ) {}

    /**
     * @return array{status: string, checked_at: string, checks: array<string, array{status: string, value: mixed, detail?: string}>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->safely(fn () => $this->database()),
            'tenant_databases' => $this->safely(fn () => $this->tenantDatabases()),
            'cache' => $this->safely(fn () => $this->cache()),
            'queue_depth' => $this->safely(fn () => $this->queueDepth()),
            'failed_jobs' => $this->safely(fn () => $this->failedJobs()),
            'sync_errors' => $this->safely(fn () => $this->syncErrors()),
            'rule_cache_hit_rate' => $this->safely(fn () => $this->ruleCache()),
            'backup' => $this->safely(fn () => $this->backup()),
            'restore_drill' => $this->safely(fn () => $this->restoreDrill()),
            'scheduler' => $this->safely(fn () => $this->scheduler()),
            'open_alerts' => $this->safely(fn () => $this->openAlerts()),
        ];

        $worst = array_reduce($checks, fn (string $worst, array $check) => self::RANK[$check['status']] > self::RANK[$worst] ? $check['status'] : $worst, 'ok');

        return ['status' => $worst, 'checked_at' => now('UTC')->toIso8601String(), 'checks' => $checks];
    }

    /**
     * @return array{status: string, value: mixed, detail?: string}
     */
    private function safely(callable $check): array
    {
        try {
            return $check();
        } catch (Throwable $exception) {
            // The type only: messages can quote hosts, queries or settings.
            return ['status' => 'fail', 'value' => null, 'detail' => class_basename($exception)];
        }
    }

    /**
     * ok below the limit, warn from it, fail from twice the limit.
     *
     * @return array{status: string, value: int}
     */
    private function level(int $value, int $warnAt): array
    {
        $status = $value >= $warnAt * 2 ? 'fail' : ($value >= $warnAt ? 'warn' : 'ok');

        return ['status' => $status, 'value' => $value];
    }

    private function database(): array
    {
        DB::table('migrations')->limit(1)->count();

        return ['status' => 'ok', 'value' => DB::connection()->getDriverName()];
    }

    /**
     * Every dedicated / regional client database answers and has its tables
     * (Phase 10); a move that stays unfinished too long is a warning.
     */
    private function tenantDatabases(): array
    {
        $names = $this->databases->names();
        $broken = [];

        foreach ($names as $name) {
            try {
                $this->placements->assertMigrated($this->databases->connectionFor($name));
            } catch (Throwable $exception) {
                $broken[$name] = class_basename($exception);
            }
        }

        $stuck = TenantPlacement::query()
            ->where('status', PlacementStatus::Moving->value)
            ->where('status_changed_at', '<', now()->subMinutes((int) config('monitoring.health.tenant_move_max_minutes', 120)))
            ->count();

        return [
            'status' => $broken !== [] ? 'fail' : ($stuck > 0 ? 'warn' : 'ok'),
            'value' => ['configured' => count($names), 'unavailable' => $broken, 'stuck_moves' => $stuck],
        ];
    }

    private function cache(): array
    {
        $key = 'monitoring:health_probe';
        Cache::put($key, 'ok', 60);

        return ['status' => Cache::get($key) === 'ok' ? 'ok' : 'fail', 'value' => config('cache.default')];
    }

    private function queueDepth(): array
    {
        $depth = array_sum(array_map(fn (string $queue) => (int) Queue::size($queue), (array) config('monitoring.health.queues')));

        return $this->level($depth, (int) config('monitoring.health.queue_depth_warn'));
    }

    private function failedJobs(): array
    {
        $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return [...$this->level($count, (int) config('monitoring.health.failed_jobs_warn')), 'detail' => 'last 24 hours'];
    }

    private function syncErrors(): array
    {
        $count = DB::table('sync_operations')->where('status', 'rejected')->where('received_at', '>=', now()->subDay())->count();

        return [...$this->level($count, (int) config('monitoring.health.sync_errors_warn')), 'detail' => 'rejected offline changes, last 24 hours'];
    }

    private function ruleCache(): array
    {
        $hits = $this->metrics->recent('rule_cache.hit');
        $misses = $this->metrics->recent('rule_cache.miss');

        if ($hits + $misses < 50) {
            return ['status' => 'ok', 'value' => null, 'detail' => 'not enough lookups yet'];
        }

        // Whole percent: no floats in the code (NoFloatMoneyTest).
        $percent = intdiv($hits * 100, $hits + $misses);

        return ['status' => $percent < (int) config('monitoring.health.rule_cache_hit_rate_warn_percent') ? 'warn' : 'ok', 'value' => $percent, 'detail' => 'percent of lookups served from the cache'];
    }

    private function backup(): array
    {
        $last = $this->lastPlatformEntry(['backup.created']);
        $maxHours = (int) config('monitoring.health.backup_max_age_hours');

        if ($last === null) {
            return ['status' => 'warn', 'value' => null, 'detail' => 'no backup recorded yet'];
        }

        return ['status' => $last['at']->lt(now()->subHours($maxHours)) ? 'fail' : 'ok', 'value' => $last['at']->toIso8601String()];
    }

    private function restoreDrill(): array
    {
        $last = $this->lastPlatformEntry(['backup.restore_drill_passed', 'backup.restore_drill_failed']);

        if ($last === null) {
            return ['status' => 'warn', 'value' => null, 'detail' => 'no restore drill recorded yet'];
        }

        $status = match (true) {
            $last['action'] === 'backup.restore_drill_failed' => 'fail',
            $last['at']->lt(now()->subDays((int) config('monitoring.health.drill_max_age_days'))) => 'warn',
            default => 'ok',
        };

        return ['status' => $status, 'value' => $last['at']->toIso8601String(), 'detail' => $last['action']];
    }

    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT);

        if (! is_int($beat)) {
            return ['status' => 'warn', 'value' => null, 'detail' => 'no heartbeat yet (is schedule:work or the cron running?)'];
        }

        $silent = now()->getTimestamp() - $beat;

        return ['status' => $silent > (int) config('monitoring.health.scheduler_max_silence_minutes') * 60 ? 'fail' : 'ok', 'value' => CarbonImmutable::createFromTimestamp($beat)->toIso8601String()];
    }

    private function openAlerts(): array
    {
        $open = SecurityAlert::query()->whereNull('acknowledged_at')->where('last_seen_at', '>=', now()->subDays(7))->get(['severity']);
        $bySeverity = array_map(fn (string $severity) => $open->where('severity', $severity)->count(), array_combine(array_keys(SecurityAlert::SEVERITIES), array_keys(SecurityAlert::SEVERITIES)));

        return ['status' => $bySeverity['high'] > 0 ? 'warn' : 'ok', 'value' => $bySeverity];
    }

    /**
     * @param  list<string>  $actions
     * @return array{action: string, at: CarbonImmutable}|null
     */
    private function lastPlatformEntry(array $actions): ?array
    {
        $row = DB::table('audit_logs')->whereNull('organization_id')->whereIn('action', $actions)->orderByDesc('created_at')->first(['action', 'created_at']);

        return $row === null ? null : ['action' => $row->action, 'at' => CarbonImmutable::parse($row->created_at, 'UTC')];
    }
}
