<?php

namespace Modules\EducationLectures\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationLectures\Models\Lecture;
use Modules\EducationLectures\Services\Classroom;

/**
 * Subjects of the open session that have a teacher but no lecture published
 * for longer than the rule education_lectures.quiet_days, the longest quiet
 * first; each opens the lectures of that subject and section. A teacher
 * sees their own sections.
 */
final class QuietSubjects implements DashboardWidget
{
    private const SHOWN = 8;

    public function data(CurrentContext $context): array
    {
        $place = LecturePlace::of($context);
        if ($place === null) {
            return WidgetData::list([]);
        }
        $academic = app(AcademicDirectory::class);
        $classroom = app(Classroom::class);
        $session = collect($academic->sessions($place->company))->firstWhere('status', 'open');
        if ($session === null) {
            return WidgetData::list([]);
        }
        $days = (int) app(RuleResolver::class)->get('education_lectures.quiet_days', app(RuleContextFactory::class)->forOrganization($place->unit));
        $today = $classroom->today($place->company);
        $sections = collect($academic->sessionSections($place->company, $session['id'], $place->units))
            ->when($place->sections !== null, fn ($all) => $all->whereIn('id', $place->sections))->keyBy('id');
        $taught = collect($academic->subjectTeachers($place->company, $sections->keys()->all()));
        // The latest lesson day of each subject and section (portable: no raw SQL).
        $last = $classroom->query(Lecture::class, $place->company)->whereIn('section_id', $sections->keys())->where('status', 'published')
            ->get(['section_id', 'subject_id', 'lesson_on'])
            ->groupBy(fn (Lecture $lecture) => "{$lecture->section_id}|{$lecture->subject_id}")
            ->map(fn ($rows) => $rows->max(fn (Lecture $lecture) => $lecture->lesson_on->toDateString()));

        $quiet = $taught->map(fn (array $row) => [...$row, 'last_on' => $last["{$row['section_id']}|{$row['subject_id']}"] ?? null])
            ->filter(fn (array $row) => $row['last_on'] === null || $row['last_on'] < $today->subDays($days)->toDateString())
            ->sortBy(fn (array $row) => $row['last_on'] ?? '0000-00-00')->take(self::SHOWN)->values();
        $subjects = $academic->subjects($place->company, $quiet->pluck('subject_id')->unique()->values()->all());
        $levels = collect($academic->levels($place->company))->keyBy('id');
        $text = fn (?array $texts) => (string) ($texts[app()->getLocale()] ?? $texts[config('app.fallback_locale')] ?? (is_array($texts) ? reset($texts) : '') ?: '');

        return WidgetData::list($quiet->map(fn (array $row) => [
            'label' => $text($subjects[$row['subject_id']]['name'] ?? null).' · '.$text($levels[$sections[$row['section_id']]['level_id']]['name'] ?? null).' '.$sections[$row['section_id']]['name'],
            'meta' => $row['last_on'] === null ? __('education_lectures::dashboard.quiet_never') : __('education_lectures::dashboard.quiet_since'),
            'date' => $row['last_on'],
            'path' => "/lectures?section={$row['section_id']}&subject={$row['subject_id']}",
            'tone' => 'warn',
        ])->all());
    }
}
