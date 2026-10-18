<?php

namespace Modules\StudentAttendance\Services;

use App\Models\User;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Models\Mark;
use Modules\StudentAttendance\Models\Notice;
use Modules\StudentAttendance\Models\Period;
use Modules\StudentAttendance\Models\Sheet;

/**
 * Telling families about today (rule student_attendance.notify_absent, off
 * by default: SMS costs money), a few minutes after the sheet is saved so a
 * slip of the finger can be put right first; the state is read again when
 * the message goes.
 *
 * - Once a day: a student absent on the day's sheet.
 * - By period: a student absent in the day's first period taken; and, with
 *   student_attendance.notify_class_skip, one present earlier that day but
 *   absent in a later period ("skipped").
 * - Each kind once per student and day; only for today's sheets. To
 *   portal-linked logins and guardians with a login, then guardians' own
 *   phone or email kept on the student.
 */
class AbsenceNotices
{
    public function __construct(
        private Register $register,
        private AcademicDirectory $academic,
        private Notifier $notifier,
    ) {}

    /** @return array{enabled: bool, channels: list<string>, delay_minutes: int} */
    public function rule(Organization $company, ?string $unitId = null): array
    {
        $value = (array) $this->register->rule('student_attendance.notify_absent', $company, $unitId);

        return [
            'enabled' => (bool) ($value['enabled'] ?? false),
            'channels' => array_values((array) ($value['channels'] ?? ['sms'])),
            'delay_minutes' => (int) ($value['delay_minutes'] ?? 30),
        ];
    }

    /** After a sheet of today was saved: tell who should be told now. How many students' families were told. */
    public function forSheet(Organization $company, string $sheetId): int
    {
        $sheet = $this->register->query(Sheet::class, $company)->find($sheetId);
        if ($sheet === null || ! $sheet->taken_on->equalTo($this->register->today($company))) {
            return 0;
        }
        $rule = $this->rule($company, $sheet->unit_id);
        if (! $rule['enabled']) {
            return 0;
        }
        $absent = $this->register->query(Mark::class, $company)->where('sheet_id', $sheet->getKey())->where('status', 'absent')->pluck('student_id')->all();
        if ($absent === []) {
            return 0;
        }

        $told = 0;
        if ($sheet->slot === Sheet::DAY) {
            foreach ($absent as $studentId) {
                $told += $this->tell($company, $sheet, $studentId, 'absent', $rule['channels']) ? 1 : 0;
            }

            return $told;
        }

        // By period: the day's first period taken decides who is not in school; later ones, who skipped.
        $daySheets = $this->register->query(Sheet::class, $company)->where('section_id', $sheet->section_id)
            ->whereDate('taken_on', $sheet->taken_on->toDateString())->where('slot', '!=', Sheet::DAY)->get();
        $numbers = $this->register->query(Period::class, $company)->whereIn('id', $daySheets->pluck('period_id'))->pluck('number', 'id');
        $first = $daySheets->sortBy(fn (Sheet $other) => $numbers[$other->period_id] ?? PHP_INT_MAX)->first();
        if ($first?->getKey() === $sheet->getKey()) {
            foreach ($absent as $studentId) {
                $told += $this->tell($company, $sheet, $studentId, 'absent', $rule['channels']) ? 1 : 0;
            }

            return $told;
        }
        if (! (bool) $this->register->rule('student_attendance.notify_class_skip', $company, $sheet->unit_id)) {
            return 0;
        }
        $earlier = $daySheets->filter(fn (Sheet $other) => ($numbers[$other->period_id] ?? PHP_INT_MAX) < ($numbers[$sheet->period_id] ?? PHP_INT_MAX))->pluck('id')->all();
        $cameIn = $earlier === [] ? [] : $this->register->query(Mark::class, $company)->whereIn('sheet_id', $earlier)->whereIn('student_id', $absent)
            ->whereIn('status', ['present', 'late'])->distinct()->pluck('student_id')->all();
        foreach ($cameIn as $studentId) {
            $told += $this->tell($company, $sheet, $studentId, 'skipped', $rule['channels']) ? 1 : 0;
        }

        return $told;
    }

    /** Whether the family was told now (false: told before, or nobody to tell). */
    private function tell(Organization $company, Sheet $sheet, string $studentId, string $kind, array $channels): bool
    {
        $day = $sheet->taken_on->toDateString();
        if ($this->register->query(Notice::class, $company)->where('student_id', $studentId)->whereDate('taken_on', $day)->where('kind', $kind)->exists()) {
            return false;
        }
        $student = $this->academic->student($company, $studentId);
        if ($student === null) {
            return false;
        }
        $period = $sheet->period_id === null ? null : $this->register->query(Period::class, $company)->find($sheet->period_id);
        $subject = $sheet->subject_id === null ? null : ($this->academic->subjects($company, [$sheet->subject_id])[$sheet->subject_id]['name'] ?? null);
        $pick = fn (?array $texts, string $locale) => (string) ($texts[$locale] ?? $texts['en'] ?? collect($texts ?? [])->first() ?? '');
        $values = fn (string $locale) => [
            'organization' => $company->displayName($locale),
            'student' => (string) $student['name'],
            'date' => $sheet->taken_on->locale($locale)->isoFormat('LL'),
            'period' => $pick($period?->texts('name'), $locale),
            'subject' => $pick($subject, $locale),
        ];
        $messages = $this->send($company, $studentId, "student_attendance.{$kind}", $values, $channels);
        if ($messages === 0) {
            return false;
        }
        try {
            (new Notice)->fill(['organization_id' => $company->getKey(), 'student_id' => $studentId, 'taken_on' => $day, 'kind' => $kind,
                'sheet_id' => $sheet->getKey(), 'messages' => $messages])->save();
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
}
