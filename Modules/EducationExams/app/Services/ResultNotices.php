<?php

namespace Modules\EducationExams\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Models\Combination;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\Notice;
use Modules\EducationExams\Models\Result;
use NumberFormatter;

/**
 * Telling families a published result (rule education_exams.notify_results,
 * off by default): the student's GPA and letter with a link to the portal,
 * by SMS or mail, to portal-linked members and to guardians who receive
 * notices on the phone or email kept on the student. A held result (fees
 * owed, ResultHolds) goes without grades: contact the office. Once per
 * result when published; the office may send it again once a day.
 */
class ResultNotices
{
    public function __construct(
        private ExamOffice $office,
        private AcademicDirectory $academic,
        private ResultHolds $holds,
        private Notifier $notifier,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** @return array{enabled: bool, channels: list<string>} */
    public function rule(Organization $company): array
    {
        $value = (array) $this->rules->get('education_exams.notify_results', $this->contexts->forOrganization($company));

        return ['enabled' => (bool) ($value['enabled'] ?? false), 'channels' => array_values((array) ($value['channels'] ?? ['sms', 'mail']))];
    }

    /** After publishing: each student's family once, when the rule is on. How many students were told. */
    public function published(Organization $company, string $sourceType, string $sourceId): int
    {
        if (! $this->rule($company)['enabled']) {
            return 0;
        }
        $told = 0;
        foreach ($this->rows($company, $sourceType, $sourceId, null) as $row) {
            $told += $this->tell($company, $row, 'published', "result:{$row->getKey()}:published", null) === 'sent' ? 1 : 0;
        }

        return $told;
    }

    /**
     * The office sends published results again (rule on or not: they asked), once a day per student.
     *
     * @param  list<string>|null  $studentIds  null: everyone in the results
     * @return array{sent: int, already: int, no_contact: int}
     */
    public function sendAgain(Organization $company, string $sourceType, string $sourceId, ?array $studentIds, User $actor): array
    {
        $result = ['sent' => 0, 'already' => 0, 'no_contact' => 0];
        $today = $this->office->today($company)->toDateString();
        foreach ($this->rows($company, $sourceType, $sourceId, $studentIds) as $row) {
            $result[match ($this->tell($company, $row, 'again', "result:{$row->getKey()}:again:{$today}", $actor)) {
                'sent' => 'sent', 'already' => 'already', default => 'no_contact',
            }]++;
        }
        $this->audit->record('education_exams.results_sent', null, new: ['source_type' => $sourceType, 'source_id' => $sourceId, ...$result], actor: $actor, organizationId: $company->getKey());

        return $result;
    }

    /** @return iterable<Result> */
    private function rows(Organization $company, string $sourceType, string $sourceId, ?array $studentIds): iterable
    {
        return $this->office->query(Result::class, $company)->where('source_type', $sourceType)->where('source_id', $sourceId)->where('status', 'published')
            ->when($studentIds !== null, fn ($query) => $query->whereIn('student_id', $studentIds))->lazyById(200);
    }

    /** 'sent', 'already' (this key went before) or 'none' (nobody to tell). */
    private function tell(Organization $company, Result $row, string $kind, string $key, ?User $actor): string
    {
        if ($this->office->query(Notice::class, $company)->where('once_key', $key)->exists()) {
            return 'already';
        }
        $unit = Organization::query()->find($row->unit_id) ?? $company;
        $held = $this->holds->held($company, $unit, [$row->student_id]) !== [];
        $source = $row->source_type === 'exam' ? $this->office->query(Exam::class, $company)->find($row->source_id) : $this->office->query(Combination::class, $company)->find($row->source_id);
        $student = $this->academic->student($company, $row->student_id);
        $values = fn (string $locale) => [
            'organization' => $company->displayName($locale),
            'student' => (string) ($student['name'] ?? ''),
            'exam' => (string) ($source?->texts('name')[$locale] ?? $source?->texts('name')['en'] ?? collect($source?->texts('name') ?? [])->first() ?? ''),
            ...($held ? [] : ['gpa' => self::point((int) $row->gpa_centi, $locale), 'letter' => (string) $row->letter]),
        ];
        $messages = $this->send($company, $row->student_id, $held ? 'education_exams.result_held' : 'education_exams.result_published', $values, $this->rule($company)['channels']);
        if ($messages === 0) {
            return 'none';
        }
        try {
            (new Notice)->fill([
                'organization_id' => $company->getKey(), 'student_id' => $row->student_id, 'result_id' => $row->getKey(), 'kind' => $kind, 'once_key' => $key,
                'sent_on' => $this->office->today($company)->toDateString(), 'messages' => $messages, 'sent_by' => $actor?->getKey(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Two runs at once: the other one wrote it.
        }

        return 'sent';
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

    /** A grade point in the reader's digits: 417 -> "4.17", "৪.১৭". */
    public static function point(int $centi, string $locale): string
    {
        $numbers = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $digits = fn (string $text) => implode('', array_map(fn (string $digit) => $numbers->format((int) $digit), str_split($text)));

        return $digits((string) intdiv($centi, 100)).$numbers->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL).$digits(str_pad((string) ($centi % 100), 2, '0', STR_PAD_LEFT));
    }
}
