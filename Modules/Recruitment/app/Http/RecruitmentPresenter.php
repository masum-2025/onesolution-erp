<?php

namespace Modules\Recruitment\Http;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Models\Candidate;
use Modules\Recruitment\Models\CandidateFile;
use Modules\Recruitment\Models\Feedback;
use Modules\Recruitment\Models\Interview;
use Modules\Recruitment\Models\Opening;
use Modules\Recruitment\Models\StageMove;
use Modules\Recruitment\Services\CvFiles;
use Modules\Recruitment\Services\Interviews;
use Modules\Recruitment\Services\Place;
use Modules\Recruitment\Services\Stages;

/**
 * Recruitment records as the API shows them (salaries in minor units with
 * the currency). Feedback: those who manage recruitment see everyone's;
 * an interviewer sees only their own (no one leans on another's view).
 */
class RecruitmentPresenter
{
    public function __construct(
        private Place $place,
        private Stages $stages,
        private CvFiles $files,
        private Interviews $interviews,
        private EmployeeDirectory $directory,
    ) {}

    /**
     * Openings with how many applications are at each stage (unconfirmed ones not counted).
     *
     * @param  iterable<Opening>  $openings
     * @return list<array<string, mixed>>
     */
    public function openings(Organization $company, iterable $openings): array
    {
        $openings = collect($openings);
        $counts = $this->place->query(Application::class, $company)->whereIn('opening_id', $openings->map(fn (Opening $opening) => $opening->getKey())->all())
            ->where('status', Application::ACTIVE)->get(['opening_id', 'stage'])->groupBy('opening_id')
            ->map(fn (Collection $rows) => $rows->countBy('stage')->all());
        $positions = $this->directory->positions($company);
        $units = Organization::query()->whereKey($openings->pluck('unit_id')->unique()->all())->get()->keyBy(fn (Organization $unit) => $unit->getKey());

        return $openings->map(fn (Opening $opening) => [
            ...$this->opening($opening),
            'unit_name' => isset($units[$opening->unit_id]) ? $units[$opening->unit_id]->displayName(app()->getLocale()) : '',
            'position' => isset($positions[$opening->position_id]) ? ['id' => $opening->position_id, 'code' => $positions[$opening->position_id]['code'], 'title' => $positions[$opening->position_id]['title']] : null,
            'stage_counts' => (object) ($counts[$opening->getKey()] ?? []),
            'active_count' => array_sum($counts[$opening->getKey()] ?? []),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function opening(Opening $opening): array
    {
        return [
            'id' => $opening->getKey(), 'title' => $opening->textIn('title'), 'titles' => $opening->texts('title'), 'descriptions' => $opening->texts('description'),
            'unit_id' => $opening->unit_id, 'position_id' => $opening->position_id, 'employment_type' => $opening->employment_type,
            'vacancies' => $opening->vacancies, 'hired_count' => $opening->hired_count, 'currency' => $opening->currency,
            'salary_min_minor' => $opening->salary_min_minor, 'salary_max_minor' => $opening->salary_max_minor, 'show_salary' => $opening->show_salary,
            'is_public' => $opening->is_public, 'closes_on' => $opening->closes_on?->toDateString(), 'status' => $opening->status,
            'opened_at' => $opening->opened_at?->toIso8601String(), 'version' => $opening->version,
        ];
    }

    /**
     * Applications for a list (the person's name and contacts, where it stands, interviews to come).
     *
     * @param  iterable<Application>  $applications
     * @return list<array<string, mixed>>
     */
    public function applications(Organization $company, iterable $applications): array
    {
        $applications = collect($applications);
        $ids = $applications->map(fn (Application $application) => $application->getKey())->all();
        $people = $this->place->query(Candidate::class, $company)->whereKey($applications->pluck('candidate_id')->unique()->all())->get()->keyBy(fn (Candidate $candidate) => $candidate->getKey());
        $openings = $this->place->query(Opening::class, $company)->whereKey($applications->pluck('opening_id')->unique()->all())->get()->keyBy(fn (Opening $opening) => $opening->getKey());
        $next = $this->place->query(Interview::class, $company)->whereIn('application_id', $ids)->where('status', Interview::SCHEDULED)
            ->orderBy('starts_at')->get(['application_id', 'starts_at'])->groupBy('application_id')->map(fn (Collection $rows) => $rows->first()->starts_at?->toIso8601String());
        $scores = $this->place->query(Feedback::class, $company)->whereIn('application_id', $ids)->get(['application_id', 'score'])
            ->groupBy('application_id')->map(fn (Collection $rows) => round($rows->avg('score'), 1));

        return $applications->map(function (Application $application) use ($people, $openings, $next, $scores) {
            $person = $people[$application->candidate_id] ?? null;

            return [
                'id' => $application->getKey(), 'opening_id' => $application->opening_id,
                'opening_title' => isset($openings[$application->opening_id]) ? $openings[$application->opening_id]->textIn('title') : '',
                'name' => (string) $person?->name, 'email' => (string) $person?->email, 'phone' => $person?->phone,
                'stage' => $application->stage, 'status' => $application->status, 'source' => $application->source, 'rating' => $application->rating,
                'average_score' => $scores[$application->getKey()] ?? null, 'next_interview_at' => $next[$application->getKey()] ?? null,
                'employee_id' => $application->employee_id, 'applied_at' => ($application->confirmed_at ?? $application->created_at)?->toIso8601String(),
                'decided_at' => $application->decided_at?->toIso8601String(), 'version' => $application->version,
            ];
        })->values()->all();
    }

    /**
     * One application: the person, their note, files (short signed links), the history, interviews and feedback.
     *
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public function application(Organization $company, Application $application, User $viewer, bool $allFeedback, array $can): array
    {
        $files = $this->place->query(CandidateFile::class, $company)->where('application_id', $application->getKey())->orderBy('created_at')->get();
        $moves = $this->place->query(StageMove::class, $company)->where('application_id', $application->getKey())->orderBy('created_at')->get();
        $interviews = $this->place->query(Interview::class, $company)->where('application_id', $application->getKey())->orderBy('starts_at')->get();
        $feedback = $this->place->query(Feedback::class, $company)->where('application_id', $application->getKey())
            ->when(! $allFeedback, fn ($query) => $query->where('user_id', $viewer->getKey()))->get()->groupBy('interview_id');
        $names = User::query()->whereKey([...$moves->pluck('user_id')->filter()->all(), ...$interviews->pluck('interviewer_ids')->flatten()->all(), ...$feedback->flatten()->pluck('user_id')->all()])
            ->pluck('name', 'id');

        return [
            ...$this->applications($company, [$application])[0],
            'cover_note' => $application->cover_note, 'reject_reason' => $application->reject_reason,
            'files' => $files->map(fn (CandidateFile $file) => [
                'id' => $file->getKey(), 'name' => $file->name, 'mime' => $file->mime, 'size_bytes' => $file->size_bytes, 'url' => $this->files->url($company, $file),
            ])->values()->all(),
            'history' => $moves->map(fn (StageMove $move) => [
                'from' => $move->from_stage, 'to' => $move->to_stage, 'status' => $move->status, 'note' => $move->note,
                'by' => $move->user_id === null ? null : ($names[$move->user_id] ?? ''), 'at' => $move->created_at?->toIso8601String(),
            ])->values()->all(),
            'interviews' => $interviews->map(fn (Interview $interview) => [
                ...$this->interview($company, $interview, $names),
                'feedback' => ($feedback[$interview->getKey()] ?? collect())->map(fn (Feedback $item) => [
                    'by' => $names[$item->user_id] ?? '', 'mine' => $item->user_id === $viewer->getKey(), 'score' => $item->score,
                    'recommendation' => $item->recommendation, 'note' => $item->note, 'at' => $item->updated_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'stages' => $this->stages->of($company),
            'can' => $can,
        ];
    }

    /**
     * @param  Collection<string, string>|null  $names  User names by id.
     * @return array<string, mixed>
     */
    public function interview(Organization $company, Interview $interview, ?Collection $names = null): array
    {
        $names ??= User::query()->whereKey($interview->interviewer_ids)->pluck('name', 'id');

        return [
            'id' => $interview->getKey(), 'application_id' => $interview->application_id, 'starts_at' => $interview->starts_at?->toIso8601String(),
            'local' => $this->interviews->when($company, $interview, app()->getLocale()), 'minutes' => $interview->minutes, 'mode' => $interview->mode,
            'place' => $interview->place, 'status' => $interview->status, 'version' => $interview->version,
            'interviewers' => array_map(fn (string $id) => ['id' => $id, 'name' => $names[$id] ?? ''], $interview->interviewer_ids),
        ];
    }
}
