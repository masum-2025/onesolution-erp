<?php

namespace App\Platform\Monitoring\Jobs;

use App\Models\User;
use App\Platform\Monitoring\AlertText;
use App\Platform\Monitoring\Channels\PlatformAlertChannels;
use App\Platform\Monitoring\Models\SecurityAlert;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Tells everyone an alert is for (config monitoring.alerts.*.notify), once:
 * the platform operators, the person whose account it is about, the people
 * who read the organization's audit log, or the partner's owners. When a
 * channel is down the job is retried, and only what has not gone out yet is
 * sent again.
 */
class DeliverSecurityAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public string $alertId) {}

    public function handle(PlatformAlertChannels $platform, Notifier $notifier, Recipients $recipients, AlertText $text): void
    {
        $alert = SecurityAlert::query()->find($this->alertId);
        if ($alert === null || $alert->notified_at !== null) {
            return;
        }

        $notify = (array) config("monitoring.alerts.{$alert->kind}.notify", ['platform']);

        if (in_array('platform', $notify, true)) {
            $locale = (string) config('monitoring.platform.locale');
            // Each channel on its own: a Slack outage does not send the email twice.
            foreach ($platform->for($alert) as $channel) {
                $this->once($alert, "platform:{$channel}", fn () => $platform->send($channel, $text->subject($alert, $locale), $text->body($alert, $locale)));
            }
        }

        $user = in_array('person', $notify, true) ? User::query()->find($alert->user_id) : null;
        if ($user !== null) {
            // To every address the person has: someone may be trying to get in.
            $this->once($alert, 'person', fn () => $notifier->notify('security.sign_in_attempts', [$user], fn (string $locale) => [
                'count' => (string) $alert->count,
                'time' => $text->time($alert->first_seen_at, $user->timezone),
            ], partner: null, allChannels: true));
        }

        $organization = in_array('organization', $notify, true) ? Organization::query()->find($alert->organization_id) : null;
        if ($organization !== null) {
            // Times in the organization's own (or inherited) time zone.
            $timezone = app(OrganizationSettingsResolver::class)->values($organization)['timezone'] ?? null;
            $this->once($alert, 'organization', fn () => $notifier->notify('security.alert', $this->others($recipients->holding('audit.view', $organization), $alert), fn (string $locale) => [
                'organization' => $organization->displayName($locale),
                'event' => $text->title($alert->kind, $locale),
                'details' => $text->notice($alert, $locale),
                'time' => $text->time($alert->first_seen_at, $timezone),
            ], $organization->partner, $organization));
        }

        $partner = in_array('partner', $notify, true) ? Partner::query()->find($alert->partner_id) : null;
        if ($partner !== null) {
            $this->once($alert, 'partner', fn () => $notifier->notify('security.partner_alert', $this->others($recipients->partnerStaff($partner, PartnerUserRole::Owner), $alert), fn (string $locale) => [
                'partner' => $partner->name,
                'event' => $text->title($alert->kind, $locale),
                'details' => $text->notice($alert, $locale),
                'time' => $text->time($alert->first_seen_at),
            ], $partner));
        }

        $alert->forceFill(['notified_at' => now()])->save();
    }

    /**
     * The people to tell, without the one who did it: they know already.
     *
     * @param  Collection<int, User>  $people
     * @return Collection<int, User>
     */
    private function others(Collection $people, SecurityAlert $alert): Collection
    {
        return $people->reject(fn (User $person) => $person->getKey() === $alert->user_id)->values();
    }

    /**
     * Sends to one audience unless an earlier attempt already did; marked only
     * once it went out, so a failure is retried.
     */
    private function once(SecurityAlert $alert, string $audience, Closure $send): void
    {
        $key = "monitoring:delivered:{$alert->getKey()}:{$audience}";

        if (Cache::has($key)) {
            return;
        }

        $send();
        Cache::put($key, true, now()->addDay());
    }
}
