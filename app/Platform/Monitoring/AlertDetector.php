<?php

namespace App\Platform\Monitoring;

use App\Platform\Rules\RuleCatalog;
use App\Platform\Security\Events\SecurityEventRecorded;
use Illuminate\Support\Facades\Cache;

/**
 * Watches every security event (Phase 9-2) and counts it for each alert
 * kind that listens to it, per group (an account, an address, an
 * organization) in a fixed time window. At the threshold the alert is raised.
 * Counters live in the cache (Redis in production), shared by all servers.
 */
class AlertDetector
{
    public function __construct(
        private AlertService $alerts,
        private RuleCatalog $rules,
    ) {}

    public function handle(SecurityEventRecorded $recorded): void
    {
        foreach ((array) config('monitoring.alerts') as $kind => $definition) {
            if (! in_array($recorded->event, $definition['events'], true) || ! $this->applies($definition, $recorded->context)) {
                continue;
            }

            $group = $this->group($definition['group_by'], $recorded->context);
            if ($group === null) {
                continue;
            }

            $window = max(1, (int) $definition['window']);
            $counter = 'monitoring:'.$kind.':'.sha1($group).':'.intdiv(now()->getTimestamp(), $window);
            Cache::add($counter, 0, $window * 2);
            $count = (int) Cache::increment($counter);

            if ($count >= (int) $definition['threshold']) {
                $this->alerts->raise($kind, $definition, $group, $recorded->context, $count);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $context
     */
    private function applies(array $definition, array $context): bool
    {
        if (! ($definition['only_sensitive_rules'] ?? false)) {
            return true;
        }

        $rule = $context['rule'] ?? null;

        return is_string($rule) && $this->rules->has($rule) && $this->rules->get($rule)->needsApproval();
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $context
     */
    private function group(array $fields, array $context): ?string
    {
        foreach ($fields as $field) {
            if (is_scalar($context[$field] ?? null) && (string) $context[$field] !== '') {
                return $field.':'.$context[$field];
            }
        }

        return null;
    }
}
