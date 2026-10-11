<?php

namespace Modules\EducationExams\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\EducationExams\Exceptions\ExamException;
use Modules\EducationExams\Models\GradingBand;
use Modules\EducationExams\Models\GradingScheme;

/**
 * Grading schemes: how a percentage becomes a letter and a grade point.
 * The presets (database/data/grading/*.php: BD schools GPA 5, BD
 * universities UGC 4, percentage) are copied into an institution the first
 * time it opens exams; the institution changes its copy or adds its own.
 */
class Grading
{
    public function __construct(private ExamOffice $office, private AuditLogger $audit) {}

    /** @return Collection<int, GradingScheme> The institution's schemes (the presets added the first time). */
    public function schemes(Organization $company): Collection
    {
        if (! $this->office->query(GradingScheme::class, $company)->exists()) {
            $this->office->transaction($company, function () use ($company) {
                foreach ($this->presets() as $preset) {
                    $this->write($company, null, $preset);
                }
            });
        }

        return $this->office->query(GradingScheme::class, $company)->orderBy('code')->get();
    }

    /**
     * The bands of schemes, highest first, by scheme id.
     *
     * @param  list<string>  $schemeIds
     * @return array<string, list<array{min_percent_bp: int, letter: string, point_centi: int, is_pass: bool}>>
     */
    public function bands(Organization $company, array $schemeIds): array
    {
        return $this->office->query(GradingBand::class, $company)->whereIn('scheme_id', $schemeIds)->orderByDesc('min_percent_bp')->get()
            ->groupBy('scheme_id')->map(fn (Collection $bands) => $bands->map(fn (GradingBand $band) => $band->only(['min_percent_bp', 'letter', 'point_centi', 'is_pass']))->values()->all())->all();
    }

    /** @param  array<string, mixed>  $data  code, name, max_point_centi, bands: [{min_percent_bp, letter, point_centi, is_pass}] */
    public function save(Organization $company, ?GradingScheme $scheme, array $data, User $actor): GradingScheme
    {
        if (isset($data['bands']) && ! ExamMath::bandsComplete($data['bands'])) {
            throw ExamException::bandsIncomplete();
        }

        return $this->office->transaction($company, function () use ($company, $scheme, $data, $actor) {
            if ($scheme !== null) {
                $scheme = $this->office->query(GradingScheme::class, $company)->whereKey($scheme->getKey())->lockForUpdate()->firstOrFail();
                if (isset($data['base_version']) && $scheme->version !== (int) $data['base_version']) {
                    throw ExamException::versionConflict(['version' => $scheme->version]);
                }
            }
            $saved = $this->write($company, $scheme, $data);
            $this->audit->record('education_exams.scheme_saved', $saved, new: [...$saved->only(['code', 'max_point_centi', 'is_active']), 'bands' => $this->bands($company, [$saved->getKey()])[$saved->getKey()] ?? []], actor: $actor, organizationId: $company->getKey());

            return $saved;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function write(Organization $company, ?GradingScheme $scheme, array $data): GradingScheme
    {
        $scheme ??= new GradingScheme(['organization_id' => $company->getKey(), 'version' => 0]);
        $scheme->fill(collect($data)->only(['code', 'max_point_centi', 'is_active'])->all());
        if (isset($data['name'])) {
            $scheme->putTexts('name', $data['name']);
        }
        $scheme->version++;
        $scheme->save();
        if (isset($data['bands'])) {
            $this->office->query(GradingBand::class, $company)->where('scheme_id', $scheme->getKey())->delete();
            foreach ($data['bands'] as $band) {
                (new GradingBand)->fill([
                    'organization_id' => $company->getKey(), 'scheme_id' => $scheme->getKey(), 'min_percent_bp' => (int) $band['min_percent_bp'],
                    'letter' => $band['letter'], 'point_centi' => (int) ($band['point_centi'] ?? 0), 'is_pass' => (bool) $band['is_pass'],
                ])->save();
            }
        }

        return $scheme;
    }

    /** @return list<array<string, mixed>> */
    private function presets(): array
    {
        $files = glob(dirname(__DIR__, 2).'/database/data/grading/*.php') ?: [];
        sort($files);

        return array_map(fn (string $file) => require $file, $files);
    }
}
