<?php

namespace Modules\Recruitment\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Recruitment\Exceptions\RecruitmentException;
use Modules\Recruitment\Models\Opening;

/**
 * Job openings of a company: written as a draft (title and description per
 * language, the unit, an HRM position, how many people, a salary range),
 * opened (listed on the careers page when public), closed by hand, and
 * filled when as many people as needed were hired. Every change audited.
 */
class Openings
{
    public function __construct(
        private Place $place,
        private EmployeeDirectory $directory,
        private OrganizationSettingsResolver $settings,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * A new opening (a draft), or changes to one with the version seen.
     *
     * @param  array<string, mixed>  $data  Validated by OpeningRequest.
     */
    public function save(Organization $company, ?Opening $opening, array $data, User $actor): Opening
    {
        $unitId = $data['unit_id'] ?? $opening?->unit_id ?? $company->getKey();
        if (! in_array($unitId, $this->place->subtreeIds($company), true)) {
            throw ValidationException::withMessages(['unit_id' => __('recruitment::recruitment.validation.unit')]);
        }
        if (! empty($data['position_id']) && ! isset($this->directory->positions($company)[$data['position_id']])) {
            throw ValidationException::withMessages(['position_id' => __('recruitment::recruitment.validation.position')]);
        }
        $min = $data['salary_min_minor'] ?? $opening?->salary_min_minor;
        $max = $data['salary_max_minor'] ?? $opening?->salary_max_minor;
        if ($min !== null && $max !== null && $min > $max) {
            throw ValidationException::withMessages(['salary_max_minor' => __('recruitment::recruitment.validation.salary_range')]);
        }

        return $this->place->transaction($company, function () use ($company, $opening, $data, $unitId, $actor) {
            if ($opening !== null) {
                $opening = $this->fresh($company, $opening, (int) $data['base_version']);
                if ($opening->status === Opening::FILLED) {
                    throw RecruitmentException::finished();
                }
            }
            $old = $opening === null ? [] : $this->values($opening);
            $opening ??= new Opening([
                'organization_id' => $company->getKey(), 'status' => Opening::DRAFT, 'hired_count' => 0, 'version' => 0,
                'currency' => (string) ($this->settings->values($company)['currency_code'] ?? 'BDT'), 'created_by' => $actor->getKey(),
            ]);
            $opening->fill([
                ...array_intersect_key($data, array_flip(['position_id', 'employment_type', 'vacancies', 'salary_min_minor', 'salary_max_minor', 'show_salary', 'is_public', 'closes_on'])),
                'unit_id' => $unitId,
            ]);
            if (isset($data['title'])) {
                $opening->putTexts('title', $data['title']);
            }
            if (array_key_exists('description', $data)) {
                $opening->putTexts('description', array_filter((array) ($data['description'] ?? []), fn ($text) => trim((string) $text) !== '') ?: null);
            }
            if ($opening->vacancies < $opening->hired_count) {
                throw ValidationException::withMessages(['vacancies' => __('recruitment::recruitment.validation.vacancies', ['hired' => $opening->hired_count])]);
            }
            $opening->forceFill(['version' => $opening->version + 1])->save();
            $this->audit->record($old === [] ? 'recruitment.opening_added' : 'recruitment.opening_changed', $opening, old: $old, new: $this->values($opening), actor: $actor, organizationId: $company->getKey());

            return $opening;
        });
    }

    /** open | close: a draft or closed opening is opened (its closing day not past); an open one closed. */
    public function step(Organization $company, Opening $opening, string $step, int $baseVersion, User $actor): Opening
    {
        return $this->place->transaction($company, function () use ($company, $opening, $step, $baseVersion, $actor) {
            $opening = $this->fresh($company, $opening, $baseVersion);
            $old = $this->values($opening);
            if ($step === 'open') {
                if (! in_array($opening->status, [Opening::DRAFT, Opening::CLOSED], true)) {
                    throw RecruitmentException::finished();
                }
                if ($opening->closes_on !== null && $opening->closes_on->toDateString() < $this->place->today($company)->toDateString()) {
                    throw ValidationException::withMessages(['closes_on' => __('recruitment::recruitment.validation.closes_past')]);
                }
                $opening->forceFill(['status' => Opening::OPEN, 'opened_at' => $opening->opened_at ?? now(), 'closed_at' => null]);
            } else {
                if ($opening->status !== Opening::OPEN) {
                    throw RecruitmentException::notOpen();
                }
                $opening->forceFill(['status' => Opening::CLOSED, 'closed_at' => now()]);
            }
            $opening->forceFill(['version' => $opening->version + 1])->save();
            $this->audit->record($step === 'open' ? 'recruitment.opening_opened' : 'recruitment.opening_closed', $opening, old: $old, new: $this->values($opening), actor: $actor, organizationId: $company->getKey());

            return $opening;
        });
    }

    /** One more person hired: the opening is filled when as many as needed are. */
    public function hired(Organization $company, Opening $opening): void
    {
        $count = $opening->hired_count + 1;
        $opening->forceFill([
            'hired_count' => $count, 'version' => $opening->version + 1,
            ...($count >= $opening->vacancies ? ['status' => Opening::FILLED, 'closed_at' => now()] : []),
        ])->save();
    }

    /** Whether people may apply now (open, its closing day not past). */
    public function takesApplications(Organization $company, Opening $opening): bool
    {
        return $opening->status === Opening::OPEN
            && ($opening->closes_on === null || $opening->closes_on->toDateString() >= $this->place->today($company)->toDateString());
    }

    /**
     * The openings the public careers page lists (none when the company's rule turns public applying off).
     *
     * @return Collection<int, Opening>
     */
    public function public(Organization $company): Collection
    {
        if (! (bool) $this->rules->get('recruitment.public_apply', $this->contexts->forOrganization($company))) {
            return collect();
        }

        return $this->place->query(Opening::class, $company)->where('status', Opening::OPEN)->where('is_public', true)
            ->where(fn ($query) => $query->whereNull('closes_on')->orWhere('closes_on', '>=', $this->place->today($company)->toDateString()))
            ->orderByDesc('opened_at')->limit(100)->get();
    }

    private function fresh(Organization $company, Opening $opening, int $baseVersion): Opening
    {
        /** @var Opening $fresh */
        $fresh = $this->place->query(Opening::class, $company)->whereKey($opening->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw RecruitmentException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Opening $opening): array
    {
        return [
            ...$opening->only(['unit_id', 'position_id', 'employment_type', 'vacancies', 'salary_min_minor', 'salary_max_minor', 'show_salary', 'is_public', 'status']),
            'title' => $opening->texts('title'), 'closes_on' => $opening->closes_on?->toDateString(),
        ];
    }
}
