<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Deal;

/**
 * Activities with a contact: calls, meetings, visits, notes and follow-ups.
 * A task has a time and a person to do it (a member of the company); they
 * are reminded rule crm.follow_up_reminder_minutes before it, once. An op id
 * makes creating safe to retry (offline).
 */
class Activities
{
    private const FIELDS = ['kind', 'subject', 'body', 'due_at', 'assigned_to'];

    public function __construct(
        private Crm $crm,
        private Notifier $notifier,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by ActivityRequest.
     */
    public function create(Organization $company, array $data, User $actor): Activity
    {
        if (! empty($data['op_id'])) {
            $existing = $this->crm->query(Activity::class, $company)->where('op_id', $data['op_id'])->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        $contact = $this->crm->query(Contact::class, $company)->whereKey($data['contact_id'])->first();
        if ($contact === null) {
            throw ValidationException::withMessages(['contact_id' => __('crm::crm.validation.contact')]);
        }
        if ($contact->anonymized_at !== null) {
            throw CrmException::anonymized();
        }
        if (! empty($data['deal_id']) && ! $this->crm->query(Deal::class, $company)->whereKey($data['deal_id'])->where('contact_id', $contact->getKey())->exists()) {
            throw ValidationException::withMessages(['deal_id' => __('crm::crm.validation.deal')]);
        }
        $this->assertMember($company, $data['assigned_to'] ?? null);

        return $this->crm->transaction($company, function () use ($company, $data, $actor, $contact) {
            $activity = new Activity;
            $activity->fill([
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'organization_id' => $company->getKey(), 'unit_id' => $contact->unit_id, 'contact_id' => $contact->getKey(), 'deal_id' => $data['deal_id'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? (empty($data['due_at']) ? null : $actor->getKey()),
                // Notes, calls and visits logged after the fact are done when written.
                'done_at' => empty($data['due_at']) || ! empty($data['done']) ? now() : null,
                'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null, 'version' => 1,
            ]);
            $activity->save();
            $this->audit->record('crm.activity_created', $activity, new: $this->values($activity), actor: $actor, organizationId: $company->getKey());

            return $activity;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields to change; done true/false.
     */
    public function update(Organization $company, Activity $activity, int $baseVersion, array $data, User $actor): Activity
    {
        if (array_key_exists('assigned_to', $data)) {
            $this->assertMember($company, $data['assigned_to']);
        }

        return $this->crm->transaction($company, function () use ($company, $activity, $baseVersion, $data, $actor) {
            /** @var Activity $activity */
            $activity = $this->crm->query(Activity::class, $company)->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();
            if ($activity->version !== $baseVersion) {
                throw CrmException::versionConflict(['version' => $activity->version]);
            }
            $old = $this->values($activity);
            $activity->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if (array_key_exists('due_at', $data)) {
                // A new time: remind again.
                $activity->reminded_at = null;
            }
            if (array_key_exists('done', $data)) {
                $activity->done_at = $data['done'] ? ($activity->done_at ?? now()) : null;
            }
            $activity->version++;
            $activity->save();
            $this->audit->record('crm.activity_updated', $activity, old: $old, new: $this->values($activity), actor: $actor, organizationId: $company->getKey());

            return $activity;
        });
    }

    /**
     * Reminds people of their follow-ups due soon (each once), for every
     * company with CRM on. Run every few minutes by the scheduler.
     */
    public function remind(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;
        $companies = Organization::query()->whereIn('type', [OrganizationType::Company, OrganizationType::Personal])->get();
        foreach ($companies as $company) {
            if (! $this->modules->isEnabled('crm', $company)) {
                continue;
            }
            $minutes = (int) $this->rules->get('crm.follow_up_reminder_minutes', $this->contexts->forOrganization($company));
            $due = $this->crm->query(Activity::class, $company)->whereNull('done_at')->whereNull('reminded_at')->whereNotNull('assigned_to')
                ->whereNotNull('due_at')->where('due_at', '<=', $now->addMinutes($minutes))->where('due_at', '>=', $now->subDays(2))->limit(500)->get();
            if ($due->isEmpty()) {
                continue;
            }
            $contacts = $this->crm->query(Contact::class, $company)->whereKey($due->pluck('contact_id')->unique()->all())->pluck('name', 'id');
            $people = User::query()->whereKey($due->pluck('assigned_to')->unique()->all())->get()->keyBy('id');
            foreach ($due->groupBy('assigned_to') as $userId => $tasks) {
                $person = $people[$userId] ?? null;
                if ($person !== null) {
                    $this->notifier->notify('crm.follow_up_due', [$person], fn (string $locale) => [
                        'organization' => $company->displayName($locale),
                        'count' => (string) $tasks->count(),
                        'tasks' => $tasks->map(fn (Activity $task) => '• '.$task->due_at->setTimezone($this->crm->timezone($company))->format('H:i').' — '.$task->subject.' — '.($contacts[$task->contact_id] ?? ''))->implode("\n"),
                    ], $company->partner, $company, '/crm/tasks');
                    $sent += $tasks->count();
                }
                $this->crm->query(Activity::class, $company)->whereKey($tasks->pluck('id')->all())->update(['reminded_at' => $now]);
            }
        }

        return $sent;
    }

    private function assertMember(Organization $company, ?string $userId): void
    {
        if ($userId === null) {
            return;
        }
        $member = OrganizationMembership::query()->where('user_id', $userId)->whereIn('organization_id', $this->crm->subtreeIds($company))
            ->where('status', MembershipStatus::Active)->exists();
        if (! $member) {
            throw ValidationException::withMessages(['assigned_to' => __('crm::crm.validation.assignee')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Activity $activity): array
    {
        return [...$activity->only(['contact_id', 'deal_id', 'kind', 'subject', 'assigned_to']), 'due_at' => $activity->due_at?->toIso8601String(), 'done' => $activity->done_at !== null];
    }
}
