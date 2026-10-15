<?php

namespace Modules\EducationExams\Setup;

use App\Platform\Tenancy\Models\Organization;
use Modules\Education\Contracts\SetupStepProvider;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\GradingScheme;
use Modules\EducationExams\Services\ExamOffice;

/**
 * Exams on the setup checklist: a grading scheme (the country's come ready
 * the first time the exam screens open), then the first exam of the open
 * session (a help, not a need).
 */
class ExamSetupSteps implements SetupStepProvider
{
    public function __construct(private ExamOffice $office) {}

    public function module(): string
    {
        return 'education_exams';
    }

    public function steps(Organization $company, ?string $sessionId, array $unitIds): array
    {
        $schemes = $this->office->query(GradingScheme::class, $company)->where('is_active', true)->count();
        $exams = $sessionId === null ? 0 : $this->office->query(Exam::class, $company)->where('session_id', $sessionId)->count();
        $text = fn (string $key) => __("education_exams::setup.steps.{$key}");

        return [
            ['key' => 'grading', 'label' => $text('grading.label'), 'route' => '/exams/grading', 'done' => $schemes > 0, 'detail' => $schemes > 0 ? null : $text('grading.missing')],
            ['key' => 'first_exam', 'label' => $text('exam.label'), 'route' => '/exams', 'optional' => true, 'done' => $exams > 0, 'detail' => $exams > 0 ? null : $text('exam.missing')],
        ];
    }
}
