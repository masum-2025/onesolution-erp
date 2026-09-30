<?php

namespace App\Platform\Monitoring;

use App\Platform\Monitoring\Models\SecurityAlert;
use Carbon\CarbonInterface;

/**
 * Wording of alerts (lang/{locale}/monitoring.php): for the platform
 * operators (with ids, the address and what to do next), and the shorter
 * notice organizations and partners receive (no addresses, no other ids).
 */
class AlertText
{
    public function title(string $kind, string $locale): string
    {
        return __("monitoring.kinds.{$kind}.title", [], $locale);
    }

    public function subject(SecurityAlert $alert, string $locale): string
    {
        return __('monitoring.platform.subject', [
            'severity' => __("monitoring.severity.{$alert->severity}", [], $locale),
            'title' => $this->title($alert->kind, $locale),
            'count' => $alert->count,
        ], $locale);
    }

    public function body(SecurityAlert $alert, string $locale): string
    {
        $lines = [
            __("monitoring.kinds.{$alert->kind}.description", [], $locale),
            '',
            __('monitoring.platform.seen', ['count' => $alert->count, 'first' => $this->time($alert->first_seen_at), 'last' => $this->time($alert->last_seen_at)], $locale),
        ];

        foreach ((array) $alert->details as $field => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = "{$field}: ".(is_scalar($value) ? $value : json_encode($value));
            }
        }

        return implode("\n", [
            ...$lines,
            '',
            __('monitoring.platform.next', ['id' => $alert->getKey()], $locale),
        ]);
    }

    /** The notice to an organization or partner: what happened, how often, what to check. */
    public function notice(SecurityAlert $alert, string $locale): string
    {
        return __("monitoring.kinds.{$alert->kind}.notice", ['count' => $alert->count], $locale);
    }

    public function time(?CarbonInterface $time, ?string $timezone = null): string
    {
        return $time === null ? '' : $time->copy()->setTimezone($timezone ?? 'UTC')->format('Y-m-d H:i').($timezone === null ? ' UTC' : '');
    }
}
