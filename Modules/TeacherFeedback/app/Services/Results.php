<?php

namespace Modules\TeacherFeedback\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\Education\Directory\AcademicDirectory;
use Modules\TeacherFeedback\Models\Answer;
use Modules\TeacherFeedback\Models\Comment;
use Modules\TeacherFeedback\Models\Question;
use Modules\TeacherFeedback\Models\Rater;
use Modules\TeacherFeedback\Models\Round;

/**
 * What a round found, per teacher, subject and section: how many rated,
 * and the average stars of each question and overall, in hundredths
 * (no floats). An average is shown only once at least the rule's
 * teacher_feedback.min_responses people rated, so nobody can be picked
 * out; below that it is null and the screens say so.
 */
class Results
{
    public function __construct(private FeedbackOffice $office, private AcademicDirectory $academic) {}

    /**
     * @param  string|null  $teacherId  One teacher's rows only (their own page).
     * @param  list<string>  $commentStatuses  Which comments to include.
     * @return array{rows: list<array<string, mixed>>, questions: list<array<string, mixed>>}
     */
    public function of(Organization $company, Round $round, int $minResponses, ?string $teacherId = null, array $commentStatuses = ['approved']): array
    {
        $questions = $this->office->query(Question::class, $company)->where('round_id', $round->getKey())->orderBy('sort_order')->get();
        $scope = fn ($query) => $query->where('round_id', $round->getKey())->when($teacherId !== null, fn ($query) => $query->where('teacher_id', $teacherId));
        $key = fn ($row) => "{$row->teacher_id}|{$row->subject_id}|{$row->section_id}";

        $counts = $scope($this->office->query(Rater::class, $company))->get(['teacher_id', 'subject_id', 'section_id'])->countBy($key);
        // Summed in PHP from the plain rows (portable: no raw SQL).
        $stars = $scope($this->office->query(Answer::class, $company))->get(['teacher_id', 'subject_id', 'section_id', 'question_id', 'stars'])->groupBy($key);
        $comments = $scope($this->office->query(Comment::class, $company))->whereIn('status', $commentStatuses)->get()->groupBy($key);

        $rows = $counts->keys()->map(function (string $group) use ($counts, $stars, $comments, $questions, $minResponses) {
            [$teacher, $subject, $section] = explode('|', $group);
            $responses = (int) $counts[$group];
            $shown = $responses >= $minResponses;
            $answers = collect($stars[$group] ?? []);
            $average = fn ($rows) => $rows->isEmpty() ? null : intdiv((int) $rows->sum('stars') * 100 + intdiv($rows->count(), 2), $rows->count());

            return [
                'teacher_id' => $teacher, 'subject_id' => $subject, 'section_id' => $section, 'responses' => $responses, 'shown' => $shown,
                'average_centi' => $shown ? $average($answers) : null,
                'questions' => $questions->mapWithKeys(fn (Question $question) => [
                    $question->getKey() => $shown ? $average($answers->where('question_id', $question->getKey())) : null,
                ])->all(),
                // Comments only with enough responses too: a single comment could give its writer away.
                'comments' => $shown ? collect($comments[$group] ?? [])->map(fn (Comment $comment) => ['id' => $comment->getKey(), 'text' => $comment->text, 'status' => $comment->status])->values()->all() : [],
            ];
        })->sortByDesc('responses')->values()->all();

        return [
            'rows' => $rows,
            'questions' => $questions->map(fn (Question $question) => ['id' => $question->getKey(), 'text' => $question->texts('text')])->values()->all(),
        ];
    }

    /**
     * Names the screens show: teachers, subjects and sections in these rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function names(Organization $company, array $rows): array
    {
        $rows = collect($rows);
        $levels = collect($this->academic->levels($company))->keyBy('id');
        $sections = $rows->pluck('section_id')->unique()->mapWithKeys(fn (string $id) => [$id => $this->academic->section($company, $id)])->filter();

        return [
            'teachers' => (object) $this->academic->teacherNames($company, $rows->pluck('teacher_id')->unique()->values()->all()),
            'subjects' => (object) collect($this->academic->subjects($company, $rows->pluck('subject_id')->unique()->values()->all()))
                ->map(fn (array $subject) => ['code' => $subject['code'], 'name' => $subject['name']])->all(),
            'sections' => (object) $sections->map(fn (array $section) => ['name' => $section['name'], 'level' => $levels[$section['level_id']]['name'] ?? null])->all(),
        ];
    }
}
