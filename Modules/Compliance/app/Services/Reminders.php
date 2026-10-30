<?php

namespace Modules\Compliance\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Platform\Tenancy\Exceptions\TenancyException;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Models\Provider;
use Modules\Compliance\Models\ReminderSent;
use Modules\Compliance\Models\RenewalRequest;
use Modules\Compliance\Support\Validity;

/**
 * Reminders before an audit expires: at each stage of the rule
 * compliance.reminder_days (e.g. 60, 30, 15 and 7 days before) and once
 * more when it has expired. Each stage goes once per expiry day (a new
 * expiry day starts them again); one message per company and day lists
 * every audit due, to the people who manage compliance there (and above).
 */
class Reminders
{
    public function __construct(
        private TenantDatabases $databases,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Place $place,
        private Recipients $recipients,
        private Notifier $notifier,
    ) {}

    /**
     * @return list<int> Reminder days at a unit, smallest first.
     */
    public function daysFor(Organization $unit): array
    {
        $days = array_values(array_unique(array_map('intval', (array) $this->rules->get('compliance.reminder_days', $this->contexts->forOrganization($unit)))));
        sort($days);

        return $days;
    }

    /** Send what is due today in every client database. Returns how many audits were reported. */
    public function run(?CarbonImmutable $now = null): int
    {
        $sent = 0;
        foreach ([null, ...$this->databases->names()] as $database) {
            $this->databases->onConnection($this->databases->connectionFor($database), function () use ($now, &$sent) {
                $companies = Audit::query()->withoutGlobalScope(OrganizationScope::class)->whereNotNull('expires_on')->distinct()->pluck('company_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    $sent += $this->runFor($company, $now);
                }
            });
        }

        return $sent;
    }

    public function runFor(Organization $company, ?CarbonImmutable $now = null): int
    {
        $this->requestRenewals($company, $now);
        $days = $this->daysFor($company);
        if ($days === [] || ! $this->modules->isEnabled('compliance', $company)) {
            return 0;
        }
        $today = $this->place->today($company, $now);
        $audits = Audit::query()->withoutGlobalScope(OrganizationScope::class)->with(['service' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)])
            ->where('company_id', $company->getKey())->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $today->addDays(max($days))->toDateString())
            ->orderBy('expires_on')->get();

        $due = [];
        foreach ($audits as $audit) {
            $stage = Validity::stage($audit->expires_on, $today, $days);
            if ($stage === null) {
                continue;
            }
            $told = ReminderSent::query()->withoutGlobalScope(OrganizationScope::class)->where('audit_id', $audit->getKey())
                ->whereDate('expires_on', $audit->expires_on->toDateString())->where('stage', $stage)->exists();
            if ($told) {
                continue;
            }
            try {
                ReminderSent::query()->withoutGlobalScope(OrganizationScope::class)->create([
                    'organization_id' => $audit->organization_id,
                    'audit_id' => $audit->getKey(),
                    'expires_on' => $audit->expires_on->toDateString(),
                    'stage' => $stage,
                    'sent_on' => $today->toDateString(),
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Already told at this stage for this expiry day.
                continue;
            }
            $due[] = [$audit, $stage];
        }
        if ($due === []) {
            return 0;
        }

        $factories = Organization::query()->whereKey(array_unique(array_map(fn (array $pair) => $pair[0]->organization_id, $due)))->get()->keyBy(fn (Organization $unit) => $unit->getKey());
        $this->notifier->notify('compliance.expiring', $this->recipients->holding('compliance.manage', $company), fn (string $locale) => [
            'organization' => $company->displayName($locale),
            'count' => (string) count($due),
            'audits' => collect($due)->map(fn (array $pair) => '• '.$factories[$pair[0]->organization_id]?->displayName($locale).' — '
                .$pair[0]->service?->textIn('name', $locale).' — '
                .__($pair[1] < 0 ? 'compliance::notifications.expired_on' : 'compliance::notifications.expires_on', ['date' => $pair[0]->expires_on->locale($locale)->isoFormat('LL')], $locale))->implode("\n"),
        ], $company->partner, $company);

        return count($due);
    }

    /**
     * With compliance.auto_request on: a renewal request to each connected
     * provider whose line reaches compliance.request_days_before and has no
     * open request. One that cannot go (connection paused, factory not
     * shared) waits for a person; the bell keeps showing it. Returns how many went.
     */
    public function requestRenewals(Organization $company, ?CarbonImmutable $now = null): int
    {
        $context = $this->contexts->forOrganization($company);
        if ($this->rules->get('compliance.auto_request', $context) !== true || ! $this->modules->isEnabled('compliance', $company)) {
            return 0;
        }
        $today = $this->place->today($company, $now);
        $within = (int) $this->rules->get('compliance.request_days_before', $context);
        $connected = Provider::query()->withoutGlobalScope(OrganizationScope::class)->whereNotNull('connection_id')->pluck('id');
        $audits = Audit::query()->withoutGlobalScope(OrganizationScope::class)->where('company_id', $company->getKey())
            ->whereIn('provider_id', $connected->all())->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $today->addDays($within)->toDateString())
            ->whereNotIn('id', RenewalRequest::query()->withoutGlobalScope(OrganizationScope::class)->whereIn('status', RenewalRequest::OPEN)->select('audit_id'))
            ->get();

        $sent = 0;
        foreach ($audits as $audit) {
            try {
                app(Renewals::class)->request($audit, null, null, null);
                $sent++;
            } catch (TenancyException) {
                // Not possible as things are: left for a person (shown in the bell).
            }
        }

        return $sent;
    }
}
