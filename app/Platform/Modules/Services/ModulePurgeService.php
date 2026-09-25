<?php

namespace App\Platform\Modules\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\Contracts\PurgesModuleData;
use App\Platform\Modules\Enums\PurgeStatus;
use App\Platform\Modules\Exceptions\ModuleException;
use App\Platform\Modules\Models\ModulePurgeRequest;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a module's data is separate from disabling it: it needs the module
 * key typed as confirmation, waits a delay (default 7 days), can be cancelled,
 * and is audited at every step.
 */
class ModulePurgeService
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleResolver $resolver,
        private AuditLogger $audit,
    ) {}

    public function request(Organization $organization, string $key, string $confirmText, string $reason, User $actor): ModulePurgeRequest
    {
        $module = $this->registry->get($key);

        if ($confirmText !== $key) {
            throw ModuleException::purgeConfirmMismatch($key);
        }

        if ($this->resolver->fresh($organization)[$key]->enabled) {
            throw ModuleException::purgeRequiresDisabled($module->label());
        }

        return DB::transaction(function () use ($organization, $key, $reason, $actor) {
            $pending = ModulePurgeRequest::query()
                ->where('organization_id', $organization->getKey())
                ->where('module_key', $key)
                ->where('status', PurgeStatus::Pending)
                ->lockForUpdate()
                ->exists();

            if ($pending) {
                throw ModuleException::purgeAlreadyPending();
            }

            $request = ModulePurgeRequest::create([
                'organization_id' => $organization->getKey(),
                'module_key' => $key,
                'status' => PurgeStatus::Pending,
                'requested_by' => $actor->getKey(),
                'execute_after' => now()->addDays((int) config('platform_modules.purge_delay_days')),
                'reason' => $reason,
            ]);

            $this->audit->record(
                action: 'module.purge_requested',
                target: $request,
                new: ['module' => $key, 'execute_after' => $request->execute_after->toIso8601String()],
                reason: $reason,
                actor: $actor,
                organizationId: $organization->getKey(),
                partnerId: $organization->partner_id,
            );

            return $request;
        });
    }

    public function cancel(Organization $organization, string $key, string $reason, User $actor): ModulePurgeRequest
    {
        $this->registry->get($key);

        $request = ModulePurgeRequest::query()
            ->where('organization_id', $organization->getKey())
            ->where('module_key', $key)
            ->where('status', PurgeStatus::Pending)
            ->first() ?? throw ModuleException::purgeNotFound();

        $request->forceFill([
            'status' => PurgeStatus::Cancelled,
            'cancelled_by' => $actor->getKey(),
            'cancelled_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'module.purge_cancelled',
            target: $request,
            reason: $reason,
            actor: $actor,
            organizationId: $organization->getKey(),
            partnerId: $organization->partner_id,
        );

        return $request;
    }

    /**
     * Execute purge requests whose delay has passed. If the module was
     * re-enabled in the meantime, the request is cancelled instead.
     *
     * @return int Number of requests processed.
     */
    public function executeDue(): int
    {
        $processed = 0;

        ModulePurgeRequest::query()
            ->with('organization')
            ->where('status', PurgeStatus::Pending)
            ->where('execute_after', '<=', now())
            ->orderBy('execute_after')
            ->each(function (ModulePurgeRequest $request) use (&$processed) {
                $this->execute($request);
                $processed++;
            });

        return $processed;
    }

    private function execute(ModulePurgeRequest $request): void
    {
        $organization = $request->organization;

        if ($this->resolver->fresh($organization)[$request->module_key]->enabled) {
            $request->forceFill(['status' => PurgeStatus::Cancelled, 'cancelled_at' => now(), 'result' => ['cancelled' => 'module_re_enabled']])->save();

            $this->audit->record(
                action: 'module.purge_cancelled',
                target: $request,
                reason: 'Module was enabled again before the purge date.',
                organizationId: $organization->getKey(),
                partnerId: $organization->partner_id,
            );

            return;
        }

        $deleted = [];

        foreach (app()->tagged('module.purgers.'.$request->module_key) as $purger) {
            /** @var PurgesModuleData $purger */
            $deleted[$purger::class] = $purger->purge($organization);
        }

        $request->forceFill(['status' => PurgeStatus::Done, 'executed_at' => now(), 'result' => ['deleted' => $deleted]])->save();

        $this->audit->record(
            action: 'module.purge_executed',
            target: $request,
            new: ['module' => $request->module_key, 'deleted' => $deleted],
            reason: $request->reason,
            organizationId: $organization->getKey(),
            partnerId: $organization->partner_id,
        );
    }
}
