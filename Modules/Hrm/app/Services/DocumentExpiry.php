<?php

namespace Modules\Hrm\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Models\DocumentAlert;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\EmployeeDocument;

/**
 * Employee documents that expire (visa, licence, contract). The rule
 * hrm.document_expiry_alert_days (e.g. [30, 7, 1]) says when HR is told;
 * each stage is sent once per document, and once more when it has expired.
 * One message per company and day lists every document due, so HR is not
 * flooded. Only the document title, the person and the date are sent, never
 * the file or an id number.
 */
class DocumentExpiry
{
    public function __construct(
        private TenantDatabases $databases,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private OrganizationSettingsResolver $settings,
        private Recipients $recipients,
        private Notifier $notifier,
    ) {}

    /**
     * Where a document stands: expired, expiring within the reminder window, or fine (null).
     *
     * @param  list<int>  $days
     * @return array{state: 'expired'|'expiring', days_left: int, stage: int}|null
     */
    public static function state(?CarbonImmutable $expiresOn, CarbonImmutable $today, array $days): ?array
    {
        if ($expiresOn === null || $days === []) {
            return null;
        }

        // Whole calendar days between the two dates (timezones aside: both are just dates here).
        $left = (int) CarbonImmutable::parse($today->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($expiresOn->toDateString(), 'UTC'), false);
        if ($left < 0) {
            return ['state' => 'expired', 'days_left' => $left, 'stage' => DocumentAlert::EXPIRED];
        }

        sort($days);
        foreach ($days as $stage) {
            if ($left <= $stage) {
                return ['state' => 'expiring', 'days_left' => $left, 'stage' => $stage];
            }
        }

        return null;
    }

    /**
     * @return list<int> Reminder days at a unit, smallest first.
     */
    public function daysFor(Organization $unit): array
    {
        $days = array_values(array_unique(array_map('intval', (array) $this->rules->get('hrm.document_expiry_alert_days', $this->contexts->forOrganization($unit)))));
        sort($days);

        return $days;
    }

    /** Today in the organization's own timezone. */
    public function today(Organization $organization, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $zone = $this->settings->values($organization)['timezone'] ?? 'UTC';

        return ($now ?? CarbonImmutable::now())->setTimezone($zone ?: 'UTC')->startOfDay();
    }

    /**
     * Send what is due today in every client database. Returns how many documents were reported.
     */
    public function run(?CarbonImmutable $now = null): int
    {
        $sent = 0;

        foreach ([null, ...$this->databases->names()] as $database) {
            $this->databases->onConnection($this->databases->connectionFor($database), function () use ($now, &$sent) {
                $companies = $this->employed()->whereHas('documents', fn (Builder $query) => $query->whereNotNull('expires_on'))
                    ->distinct()->pluck('company_id');

                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    $sent += $this->runFor($company, $now);
                }
            });
        }

        return $sent;
    }

    private function runFor(Organization $company, ?CarbonImmutable $now): int
    {
        $days = $this->daysFor($company);
        if ($days === [] || ! $this->modules->isEnabled('hrm', $company)) {
            return 0;
        }

        $today = $this->today($company, $now);
        $due = $this->documents($company, $today->addDays(max($days)))
            ->map(fn (EmployeeDocument $document) => [$document, self::state($document->expires_on, $today, $days)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->reject(fn (array $pair) => DocumentAlert::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('document_id', $pair[0]->getKey())->where('stage', $pair[1]['stage'])->exists())
            ->values();

        if ($due->isEmpty()) {
            return 0;
        }

        foreach ($due as [$document, $state]) {
            DocumentAlert::query()->withoutGlobalScope(OrganizationScope::class)->create([
                'organization_id' => $document->organization_id,
                'document_id' => $document->getKey(),
                'stage' => $state['stage'],
                'sent_on' => $today->toDateString(),
            ]);
        }

        $this->notifier->notify(
            'hrm.document_expiring',
            $this->recipients->holding('hrm.manage', $company),
            fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'count' => (string) $due->count(),
                'documents' => $due->map(fn (array $pair) => '• '.$pair[0]->employee->full_name.' — '.$pair[0]->title.' — '
                    .__($pair[1]['state'] === 'expired' ? 'hrm::notifications.expired_on' : 'hrm::notifications.expires_on', ['date' => $pair[0]->expires_on->locale($locale)->isoFormat('LL')], $locale))->implode("\n"),
            ],
            $company->partner,
            $company,
        );

        return $due->count();
    }

    /**
     * @return Builder<Employee>
     */
    private function employed(): Builder
    {
        return Employee::query()->withoutGlobalScope(OrganizationScope::class)->where('status', '!=', EmployeeStatus::Exited->value);
    }

    /**
     * Documents of the company's employed people that expire by $until (or already have).
     *
     * @return Collection<int, EmployeeDocument>
     */
    private function documents(Organization $company, CarbonImmutable $until): Collection
    {
        return EmployeeDocument::query()->withoutGlobalScope(OrganizationScope::class)
            ->with(['employee' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)])
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $until->toDateString())
            ->whereIn('employee_id', $this->employed()->where('company_id', $company->getKey())->select('id'))
            ->orderBy('expires_on')
            ->get();
    }
}
