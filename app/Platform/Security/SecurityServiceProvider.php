<?php

namespace App\Platform\Security;

use App\Platform\Audit\AuditLog;
use App\Platform\Security\Backups\Contracts\DatabaseDumper;
use App\Platform\Security\Backups\DumperFactory;
use App\Platform\Security\Console\BackupRun;
use App\Platform\Security\Console\RestoreDrill;
use App\Platform\Security\Console\SecurityCheck;
use App\Platform\Tenancy\Context\ContextSource;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Platform hardening (Phase 8-2): the general API limit, the security log,
 * production checks, encrypted backups and the restore drill.
 */
class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecurityLog::class);
        // The dump tool for the app's database driver (MySQL or PostgreSQL).
        $this->app->bind(DatabaseDumper::class, fn ($app) => $app->make(DumperFactory::class)->forDriver(
            (string) config('database.connections.'.config('database.default').'.driver'),
        ));
    }

    public function boot(): void
    {
        $this->defineApiLimit();
        $this->mirrorAuditToSecurityLog();

        if ($this->app->runningInConsole()) {
            $this->commands([SecurityCheck::class, BackupRun::class, RestoreDrill::class]);
        }
    }

    /**
     * Every signed-in /api request counts against the person and their
     * organization, on top of each endpoint's own stricter limit. The
     * per-address limit is ThrottleByAddress (it must run before sign-in).
     */
    private function defineApiLimit(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if (($user = $request->user()) === null) {
                return Limit::none();
            }

            $limits = [Limit::perMinute((int) config('security.api.per_user'))->by('api-user:'.$user->getKey())];

            $organizationId = app(ContextSource::class)->organizationId($request);
            if (is_string($organizationId) && $organizationId !== '') {
                $limits[] = Limit::perMinute((int) config('security.api.per_organization'))->by('api-org:'.$organizationId);
            }

            return $limits;
        });
    }

    /**
     * Sensitive audit entries (sign-ins, permissions, rules, modules, exports…)
     * are also written to the security log, with ids only.
     */
    private function mirrorAuditToSecurityLog(): void
    {
        AuditLog::created(function (AuditLog $entry) {
            if (! Str::is(config('security.logged_actions', []), $entry->action)) {
                return;
            }

            $this->app->make(SecurityLog::class)->record('audit.'.$entry->action, [
                'audit_id' => $entry->getKey(),
                'user_id' => $entry->actor_user_id,
                'organization_id' => $entry->organization_id,
                'partner_id' => $entry->partner_id,
                'api_key_id' => $entry->api_key_id,
                'target_type' => $entry->target_type,
                'target_id' => $entry->target_id,
            ], str_ends_with($entry->action, '_failed') ? 'warning' : 'info');
        });
    }
}
