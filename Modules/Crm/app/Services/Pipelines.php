<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Models\Deal;
use Modules\Crm\Models\Pipeline;
use Modules\Crm\Models\Stage;

/**
 * A company's pipelines and their stages. The first time a company uses
 * CRM it gets the pipeline for its sector (database/data/pipelines.php);
 * after that the stages are its own: renamed, reordered, added, switched
 * off (never removed while deals use them). Each pipeline keeps one won
 * and one lost stage.
 */
class Pipelines
{
    public function __construct(private Crm $crm, private AuditLogger $audit) {}

    /**
     * Every pipeline with its stages in order; the sector's one made first if there is none.
     *
     * @return Collection<int, Pipeline>
     */
    public function all(Organization $company): Collection
    {
        $this->ensureDefault($company);
        $pipelines = $this->crm->query(Pipeline::class, $company)->orderByDesc('is_default')->orderBy('created_at')->get();
        $stages = $this->crm->query(Stage::class, $company)->orderBy('sort_order')->get()->groupBy('pipeline_id');
        foreach ($pipelines as $pipeline) {
            $pipeline->setRelation('stages', $stages[$pipeline->getKey()] ?? collect());
        }

        return $pipelines;
    }

    public function default(Organization $company): Pipeline
    {
        $this->ensureDefault($company);

        return $this->crm->query(Pipeline::class, $company)->where('is_default', true)->first()
            ?? $this->crm->query(Pipeline::class, $company)->orderBy('created_at')->firstOrFail();
    }

    public function ensureDefault(Organization $company): void
    {
        if ($this->crm->query(Pipeline::class, $company)->exists()) {
            return;
        }
        $templates = require dirname(__DIR__, 2).'/database/data/pipelines.php';
        $template = $templates[$company->sector_key ?? '*'] ?? $templates['*'];
        $this->crm->transaction($company, function () use ($company, $template) {
            if ($this->crm->query(Pipeline::class, $company)->lockForUpdate()->exists()) {
                return;
            }
            $pipeline = new Pipeline;
            $pipeline->fill(['organization_id' => $company->getKey(), 'key' => $template['key'], 'is_default' => true, 'is_active' => true, 'version' => 1]);
            $pipeline->putTexts('name', $template['name'])->save();
            foreach ($template['stages'] as $order => $row) {
                $stage = new Stage;
                $stage->fill(['organization_id' => $company->getKey(), 'pipeline_id' => $pipeline->getKey(), 'key' => $row['key'], 'sort_order' => ($order + 1) * 10,
                    'probability_bp' => $row['probability_bp'], 'outcome' => $row['outcome'] ?? 'open', 'is_active' => true, 'version' => 1]);
                $stage->putTexts('name', $row['name'])->save();
            }
        });
    }

    /**
     * A new pipeline, with an open, a won and a lost stage to start from.
     *
     * @param  array{name: array<string, string>, is_default?: bool}  $data
     */
    public function create(Organization $company, array $data, User $actor): Pipeline
    {
        $this->ensureDefault($company);

        return $this->crm->transaction($company, function () use ($company, $data, $actor) {
            $pipeline = new Pipeline;
            $pipeline->fill(['organization_id' => $company->getKey(), 'key' => Str::lower(Str::random(8)), 'is_default' => false, 'is_active' => true, 'version' => 1]);
            $pipeline->putTexts('name', $data['name'])->save();
            $template = (require dirname(__DIR__, 2).'/database/data/pipelines.php')['*']['stages'];
            foreach ([$template[0], $template[4], $template[5]] as $order => $row) {
                $stage = new Stage;
                $stage->fill(['organization_id' => $company->getKey(), 'pipeline_id' => $pipeline->getKey(), 'key' => $row['key'], 'sort_order' => ($order + 1) * 10,
                    'probability_bp' => $row['probability_bp'], 'outcome' => $row['outcome'] ?? 'open', 'is_active' => true, 'version' => 1]);
                $stage->putTexts('name', $row['name'])->save();
            }
            if (! empty($data['is_default'])) {
                $this->makeDefault($company, $pipeline);
            }
            $this->audit->record('crm.pipeline_created', $pipeline, new: ['name' => $pipeline->texts('name')], actor: $actor, organizationId: $company->getKey());

            return $pipeline;
        });
    }

    /**
     * @param  array{name?: array<string, string>, is_default?: bool, is_active?: bool}  $data
     */
    public function update(Organization $company, Pipeline $pipeline, int $baseVersion, array $data, User $actor): Pipeline
    {
        return $this->crm->transaction($company, function () use ($company, $pipeline, $baseVersion, $data, $actor) {
            /** @var Pipeline $pipeline */
            $pipeline = $this->locked(Pipeline::class, $company, $pipeline->getKey(), $baseVersion);
            $old = [...$pipeline->only(['is_default', 'is_active']), 'name' => $pipeline->texts('name')];
            if (isset($data['name'])) {
                $pipeline->putTexts('name', $data['name']);
            }
            if (array_key_exists('is_active', $data)) {
                if (! $data['is_active'] && $pipeline->is_default) {
                    throw ValidationException::withMessages(['is_active' => __('crm::crm.validation.default_pipeline_off')]);
                }
                $pipeline->is_active = (bool) $data['is_active'];
            }
            $pipeline->version++;
            $pipeline->save();
            if (! empty($data['is_default'])) {
                $this->makeDefault($company, $pipeline);
            }
            $this->audit->record('crm.pipeline_updated', $pipeline, old: $old, new: [...$pipeline->only(['is_default', 'is_active']), 'name' => $pipeline->texts('name')], actor: $actor, organizationId: $company->getKey());

            return $pipeline;
        });
    }

    /**
     * A stage added or changed. Its outcome is set when made and kept; the
     * pipeline's won and lost stages cannot be switched off.
     *
     * @param  array{name?: array<string, string>, probability_bp?: int, sort_order?: int, outcome?: string, is_active?: bool}  $data
     */
    public function saveStage(Organization $company, Pipeline $pipeline, ?Stage $stage, ?int $baseVersion, array $data, User $actor): Stage
    {
        return $this->crm->transaction($company, function () use ($company, $pipeline, $stage, $baseVersion, $data, $actor) {
            if ($stage === null) {
                $stage = new Stage;
                $last = (int) $this->crm->query(Stage::class, $company)->where('pipeline_id', $pipeline->getKey())->where('outcome', 'open')->max('sort_order');
                $stage->fill(['organization_id' => $company->getKey(), 'pipeline_id' => $pipeline->getKey(), 'key' => Str::lower(Str::random(8)),
                    'sort_order' => $data['sort_order'] ?? $last + 5, 'probability_bp' => $data['probability_bp'] ?? 0, 'outcome' => 'open', 'is_active' => true, 'version' => 1]);
                $old = [];
            } else {
                /** @var Stage $stage */
                $stage = $this->locked(Stage::class, $company, $stage->getKey(), $baseVersion);
                $old = [...$stage->only(['sort_order', 'probability_bp', 'is_active']), 'name' => $stage->texts('name')];
                $stage->fill(array_intersect_key($data, array_flip(['sort_order', 'probability_bp'])));
                if (array_key_exists('is_active', $data)) {
                    if (! $data['is_active'] && $stage->outcome !== 'open') {
                        throw ValidationException::withMessages(['is_active' => __('crm::crm.validation.closing_stage_off')]);
                    }
                    if (! $data['is_active'] && $this->crm->query(Deal::class, $company)->where('stage_id', $stage->getKey())->where('status', Deal::OPEN)->exists()) {
                        throw ValidationException::withMessages(['is_active' => __('crm::crm.validation.stage_in_use')]);
                    }
                    $stage->is_active = (bool) $data['is_active'];
                }
                $stage->version++;
            }
            if (isset($data['name'])) {
                $stage->putTexts('name', $data['name']);
            }
            $stage->save();
            $this->audit->record($old === [] ? 'crm.stage_created' : 'crm.stage_updated', $stage, old: $old, new: [...$stage->only(['sort_order', 'probability_bp', 'outcome', 'is_active']), 'name' => $stage->texts('name')], actor: $actor, organizationId: $company->getKey());

            return $stage;
        });
    }

    /** The stage of a pipeline a deal closes in: its won or lost stage. */
    public function closing(Organization $company, string $pipelineId, string $outcome): Stage
    {
        return $this->crm->query(Stage::class, $company)->where('pipeline_id', $pipelineId)->where('outcome', $outcome)->orderBy('sort_order')->firstOrFail();
    }

    private function makeDefault(Organization $company, Pipeline $pipeline): void
    {
        $this->crm->query(Pipeline::class, $company)->whereKeyNot($pipeline->getKey())->where('is_default', true)->update(['is_default' => false]);
        $pipeline->forceFill(['is_default' => true])->save();
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function locked(string $class, Organization $company, string $id, ?int $baseVersion)
    {
        $fresh = $this->crm->query($class, $company)->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($baseVersion !== null && $fresh->version !== $baseVersion) {
            throw CrmException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }
}
