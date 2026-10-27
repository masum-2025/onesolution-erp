<?php

namespace Modules\Appraisals\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Appraisals\Exceptions\AppraisalsException;
use Modules\Appraisals\Models\Competency;

/**
 * The competencies a company reviews people on: added (a short code, the
 * name per language), changed or switched off (never removed: past reviews
 * keep them), and "the usual ones" from the rule appraisals.default_competencies.
 */
class Competencies
{
    public function __construct(private Place $place, private RuleResolver $rules, private RuleContextFactory $contexts, private AuditLogger $audit) {}

    /** @return Collection<int, Competency> */
    public function of(Organization $company, bool $activeOnly = false): Collection
    {
        return $this->place->query(Competency::class, $company)->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')->orderBy('code')->get();
    }

    /**
     * @param  array{code: string, name: array<string, string>, is_active?: bool, base_version?: int}  $data
     */
    public function save(Organization $company, ?Competency $competency, array $data, User $actor): Competency
    {
        $code = strtoupper(trim($data['code']));

        return $this->place->transaction($company, function () use ($company, $competency, $data, $code, $actor) {
            if ($this->place->query(Competency::class, $company)->where('code', $code)->when($competency, fn ($query) => $query->whereKeyNot($competency->getKey()))->exists()) {
                throw ValidationException::withMessages(['code' => __('appraisals::appraisals.validation.code_taken')]);
            }
            if ($competency !== null) {
                $competency = $this->place->query(Competency::class, $company)->whereKey($competency->getKey())->lockForUpdate()->firstOrFail();
                if ($competency->version !== (int) ($data['base_version'] ?? 0)) {
                    throw AppraisalsException::versionConflict(['version' => $competency->version]);
                }
            }
            $added = $competency === null;
            $competency ??= new Competency(['organization_id' => $company->getKey(), 'version' => 0, 'sort_order' => $this->place->query(Competency::class, $company)->count()]);
            $competency->fill(['code' => $code, 'is_active' => (bool) ($data['is_active'] ?? true)]);
            $competency->putTexts('name', $data['name']);
            $competency->forceFill(['version' => $competency->version + 1])->save();
            $this->audit->record($added ? 'appraisals.competency_added' : 'appraisals.competency_changed', $competency,
                new: ['code' => $code, 'is_active' => $competency->is_active], actor: $actor, organizationId: $company->getKey());

            return $competency;
        });
    }

    /** The competencies the rule lists that the company does not have yet. How many were added. */
    public function addDefaults(Organization $company, User $actor): int
    {
        $have = $this->of($company)->pluck('code')->all();
        $added = 0;
        foreach ((array) $this->rules->get('appraisals.default_competencies', $this->contexts->forOrganization($company)) as $entry) {
            $code = strtoupper((string) ($entry['code'] ?? ''));
            if ($code === '' || in_array($code, $have, true)) {
                continue;
            }
            $this->save($company, null, ['code' => $code, 'name' => (array) ($entry['name'] ?? [])], $actor);
            $have[] = $code;
            $added++;
        }

        return $added;
    }
}
