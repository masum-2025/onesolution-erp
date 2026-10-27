<?php

namespace Modules\Recruitment\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Models\Candidate;
use Modules\Recruitment\Models\CandidateFile;
use Modules\Recruitment\Models\Feedback;
use Modules\Recruitment\Models\Interview;
use Modules\Recruitment\Models\Opening;
use Modules\Recruitment\Models\StageMove;

/**
 * Recruitment in the client's data export: openings, candidates (their
 * details decrypted: the export is the client's own data), applications
 * with their history, interviews and feedback, and the list of files
 * (names and kinds; the files stay on the server). Unconfirmed
 * applications are left out.
 */
class RecruitmentExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'recruitment';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        return [
            'openings' => $this->rows(Opening::class, $organization, $organizationIds, fn (Opening $opening) => [
                'id' => $opening->getKey(), 'unit_id' => $opening->unit_id, 'position_id' => $opening->position_id,
                'title' => json_encode($opening->texts('title'), JSON_UNESCAPED_UNICODE), 'employment_type' => $opening->employment_type,
                'vacancies' => $opening->vacancies, 'hired_count' => $opening->hired_count, 'currency' => $opening->currency,
                'salary_min_minor' => $opening->salary_min_minor, 'salary_max_minor' => $opening->salary_max_minor, 'is_public' => $opening->is_public,
                'closes_on' => $opening->closes_on?->toDateString(), 'status' => $opening->status,
            ]),
            'candidates' => $this->rows(Candidate::class, $organization, $organizationIds, fn (Candidate $candidate) => [
                'id' => $candidate->getKey(), 'name' => $candidate->name, 'email' => $candidate->email, 'phone' => $candidate->phone,
                'consent_at' => $candidate->consent_at?->toIso8601String(),
            ]),
            'applications' => $this->rows(Application::class, $organization, $organizationIds, fn (Application $application) => $application->status === Application::UNCONFIRMED ? null : [
                'id' => $application->getKey(), 'opening_id' => $application->opening_id, 'candidate_id' => $application->candidate_id,
                'stage' => $application->stage, 'status' => $application->status, 'source' => $application->source, 'rating' => $application->rating,
                'reject_reason' => $application->reject_reason, 'employee_id' => $application->employee_id, 'decided_at' => $application->decided_at?->toIso8601String(),
            ]),
            'history' => $this->rows(StageMove::class, $organization, $organizationIds, fn (StageMove $move) => [
                'application_id' => $move->application_id, 'from' => $move->from_stage, 'to' => $move->to_stage, 'status' => $move->status,
                'note' => $move->note, 'user_id' => $move->user_id, 'at' => $move->created_at?->toIso8601String(),
            ]),
            'interviews' => $this->rows(Interview::class, $organization, $organizationIds, fn (Interview $interview) => [
                'id' => $interview->getKey(), 'application_id' => $interview->application_id, 'starts_at' => $interview->starts_at?->toIso8601String(),
                'minutes' => $interview->minutes, 'mode' => $interview->mode, 'place' => $interview->place, 'status' => $interview->status,
                'interviewer_ids' => implode(' ', $interview->interviewer_ids),
            ]),
            'feedback' => $this->rows(Feedback::class, $organization, $organizationIds, fn (Feedback $feedback) => [
                'interview_id' => $feedback->interview_id, 'application_id' => $feedback->application_id, 'user_id' => $feedback->user_id,
                'score' => $feedback->score, 'recommendation' => $feedback->recommendation, 'note' => $feedback->note,
            ]),
            'files' => $this->rows(CandidateFile::class, $organization, $organizationIds, fn (CandidateFile $file) => [
                'application_id' => $file->application_id, 'name' => $file->name, 'mime' => $file->mime, 'size_bytes' => $file->size_bytes,
            ]),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $organizationIds
     * @return iterable<array<string, mixed>>
     */
    private function rows(string $model, Organization $organization, array $organizationIds, callable $row): iterable
    {
        foreach (array_chunk($organizationIds, 500) as $chunk) {
            foreach ($model::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $chunk)->orderBy('id')->lazy(500) as $record) {
                $values = $row($record);
                if ($values !== null) {
                    yield $values;
                }
            }
        }
    }
}
