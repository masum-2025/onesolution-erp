<?php

namespace Modules\EducationFees\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Models\Receipt;
use Modules\EducationFees\Models\Reminder;

/**
 * Telling families about fees, by mail or SMS in their language:
 *
 * - before and after a bill's due date (rule education_fees.reminders:
 *   off by default; days before, days after, the local hour, channels),
 *   once per bill and step, never for a paid or cancelled bill;
 * - when the office asks, a student's dues in one message, once a day;
 * - money received, when rule education_fees.notify_payment is on.
 *
 * To whom: people linked to the student in the portal (the student, a
 * parent), and guardians who receive notices: by their login when they
 * have one, else on the phone or email kept on the student's record.
 */
class FeeReminders
{
    public function __construct(
        private FeeOffice $office,
        private AcademicDirectory $academic,
        private Notifier $notifier,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** The rule as the screens and the hourly run read it. */
    public function rule(Organization $unit): array
    {
        return (array) $this->rules->get('education_fees.reminders', $this->contexts->forOrganization($unit)) + ['enabled' => false, 'before_days' => [], 'after_days' => [], 'hour' => 10, 'channels' => ['sms', 'mail']];
    }

    /** Due-date reminders of the institution for today; how many bills were reminded. */
    public function sendDue(Organization $company): int
    {
        $rule = $this->rule($company);
        if (! $rule['enabled']) {
            return 0;
        }
        $today = $this->office->today($company);
        $steps = [];
        foreach ((array) $rule['before_days'] as $days) {
            $steps[$today->addDays((int) $days)->toDateString()] = ['before_'.(int) $days, 'education_fees.due_soon'];
        }
        foreach ((array) $rule['after_days'] as $days) {
            $steps[$today->subDays((int) $days)->toDateString()] = ['after_'.(int) $days, 'education_fees.overdue'];
        }
        if ($steps === []) {
            return 0;
        }
        $bills = $this->office->query(Bill::class, $company)->where('status', 'open')->whereIn('due_date', array_keys($steps))->whereColumn('paid_minor', '<', 'total_minor')->get();
        $count = 0;
        foreach ($bills as $bill) {
            [$kind, $key] = $steps[$bill->due_date->toDateString()];
            $count += $this->once($company, $bill->student_id, $bill->getKey(), $kind, "bill:{$bill->getKey()}:{$kind}", null,
                fn () => $this->send($company, $bill->student_id, $key, fn (string $locale) => $this->billValues($company, $bill, $locale), (array) $rule['channels'])) ? 1 : 0;
        }

        return $count;
    }

    /**
     * The office reminds students of what they owe now (one message each, once a day).
     *
     * @param  list<string>  $studentIds
     * @return array{reminded: int, skipped: int}
     */
    public function remindStudents(Organization $company, array $studentIds, User $actor): array
    {
        $today = $this->office->today($company)->toDateString();
        $bills = $this->office->query(Bill::class, $company)->whereIn('student_id', $studentIds)->where('status', 'open')->get()->groupBy('student_id');
        $channels = (array) $this->rule($company)['channels'];
        $result = ['reminded' => 0, 'skipped' => 0];
        foreach ($studentIds as $studentId) {
            /** @var Collection<int, Bill> $open */
            $open = collect($bills[$studentId] ?? [])->filter(fn (Bill $bill) => $bill->balanceMinor() > 0);
            if ($open->isEmpty()) {
                $result['skipped']++;

                continue;
            }
            $sent = $this->once($company, $studentId, null, 'manual', "student:{$studentId}:manual:{$today}", $actor,
                fn () => $this->send($company, $studentId, 'education_fees.dues_reminder', fn (string $locale) => [
                    ...$this->studentValues($company, $studentId, $locale),
                    'amount' => $this->money($company, (int) $open->sum(fn (Bill $bill) => $bill->balanceMinor()), $locale),
                    'bills' => (string) $open->count(),
                    'due' => $open->min(fn (Bill $bill) => $bill->due_date->toDateString()),
                ], $channels));
            $result[$sent ? 'reminded' : 'skipped']++;
        }
        $this->audit->record('education_fees.reminders_sent', null, new: $result, actor: $actor, organizationId: $company->getKey());

        return $result;
    }

    /** Money received, told to the family when the rule asks for it. */
    public function paymentReceived(Organization $company, Receipt $receipt): void
    {
        if (! $this->rules->get('education_fees.notify_payment', $this->contexts->forOrganization($company))) {
            return;
        }
        $channels = (array) $this->rule($company)['channels'];
        $this->once($company, $receipt->student_id, null, 'paid', "receipt:{$receipt->getKey()}:paid", null,
            fn () => $this->send($company, $receipt->student_id, 'education_fees.payment_received', fn (string $locale) => [
                ...$this->studentValues($company, $receipt->student_id, $locale),
                'amount' => $this->money($company, $receipt->amount_minor, $locale),
                'number' => $receipt->number,
            ], $channels));
    }

    /** Sends once per key; false when it was sent already or nobody could be told. */
    private function once(Organization $company, string $studentId, ?string $billId, string $kind, string $key, ?User $actor, callable $send): bool
    {
        if ($this->office->query(Reminder::class, $company)->where('once_key', $key)->exists()) {
            return false;
        }
        $messages = $send();
        if ($messages === 0) {
            return false;
        }
        try {
            (new Reminder)->fill([
                'organization_id' => $company->getKey(), 'student_id' => $studentId, 'bill_id' => $billId, 'kind' => $kind, 'once_key' => $key,
                'sent_on' => $this->office->today($company)->toDateString(), 'messages' => $messages, 'sent_by' => $actor?->getKey(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Two runs at once: the other one wrote it.
        }

        return true;
    }

    /**
     * Logins first (portal links and guardians with a login), then guardians' own phone or email.
     *
     * @param  list<string>  $channels
     */
    private function send(Organization $company, string $studentId, string $key, callable $values, array $channels): int
    {
        $userIds = PortalLink::query()->where('organization_id', $company->getKey())->where('subject_type', 'education.student')->where('subject_id', $studentId)
            ->where('status', PortalLink::ACTIVE)->pluck('user_id')->filter()->all();
        $contacts = $this->academic->noticeContacts($company, [$studentId])[$studentId] ?? [];
        $userIds = array_values(array_unique([...$userIds, ...array_filter(array_column($contacts, 'user_id'))]));
        $users = User::query()->whereKey($userIds)->get();
        $partner = $company->partner;

        $deliveries = $users->isEmpty() ? [] : $this->notifier->notify($key, $users, $values, $partner, $company);
        $withoutLogin = array_filter($contacts, fn (array $contact) => $contact['user_id'] === null || ! in_array($contact['user_id'], $users->modelKeys(), true));

        return count($deliveries) + count($this->notifier->notifyContacts($key, array_map(fn (array $contact) => [
            'name' => $contact['name'], 'phone' => $contact['phone'], 'email' => $contact['email'],
        ], $withoutLogin), $values, $partner, $company, $channels));
    }

    /** @return array<string, string> */
    private function billValues(Organization $company, Bill $bill, string $locale): array
    {
        return [
            ...$this->studentValues($company, $bill->student_id, $locale),
            'amount' => $this->money($company, $bill->balanceMinor(), $locale),
            'number' => (string) $bill->number,
            'due' => $bill->due_date->toDateString(),
        ];
    }

    /** @return array<string, string> */
    private function studentValues(Organization $company, string $studentId, string $locale): array
    {
        $student = $this->academic->student($company, $studentId);

        return ['organization' => $company->displayName($locale), 'student' => (string) ($student['name'] ?? '')];
    }

    private function money(Organization $company, int $minor, string $locale): string
    {
        return MoneyText::format($minor, $this->office->currency($company), $locale);
    }
}
