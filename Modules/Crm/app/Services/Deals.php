<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Events\DealWon;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Deal;
use Modules\Crm\Models\Pipeline;
use Modules\Crm\Models\Stage;

/**
 * Deals: an opportunity with a contact, in a pipeline. Moving it to the
 * pipeline's won or lost stage closes it (lost needs a reason); moving a
 * closed deal back to an open stage opens it again. Every move is audited.
 */
class Deals
{
    private const FIELDS = ['title', 'value_minor', 'expected_on', 'owner_id'];

    public function __construct(private Crm $crm, private Pipelines $pipelines, private Fields $fields, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by DealRequest.
     */
    public function create(Organization $company, string $unitId, array $data, User $actor): Deal
    {
        $contact = $this->contact($company, $data['contact_id']);
        $pipeline = isset($data['pipeline_id']) ? $this->crm->query(Pipeline::class, $company)->whereKey($data['pipeline_id'])->where('is_active', true)->first() : $this->pipelines->default($company);
        if ($pipeline === null) {
            throw ValidationException::withMessages(['pipeline_id' => __('crm::crm.validation.pipeline')]);
        }
        $stage = isset($data['stage_id']) ? $this->stage($company, $pipeline->getKey(), $data['stage_id'])
            : $this->crm->query(Stage::class, $company)->where('pipeline_id', $pipeline->getKey())->where('outcome', 'open')->where('is_active', true)->orderBy('sort_order')->firstOrFail();
        $extra = $this->fields->apply($company, 'deal', (array) ($data['extra'] ?? []), [], true);

        return $this->crm->transaction($company, function () use ($company, $unitId, $data, $actor, $contact, $pipeline, $stage, $extra) {
            $deal = new Deal;
            $deal->fill([
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'organization_id' => $company->getKey(), 'unit_id' => $unitId, 'contact_id' => $contact->getKey(), 'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $stage->getKey(), 'currency_code' => $this->crm->currency($company), 'status' => self::statusOf($stage),
                'owner_id' => $data['owner_id'] ?? $actor->getKey(), 'extra' => $extra ?: null, 'created_by' => $actor->getKey(), 'version' => 1,
            ]);
            $deal->value_minor ??= 0;
            if ($deal->status !== Deal::OPEN) {
                $deal->closed_at = now();
            }
            $deal->save();
            $this->audit->record('crm.deal_created', $deal, new: $this->values($deal), actor: $actor, organizationId: $company->getKey());

            return $deal;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields to change.
     */
    public function update(Organization $company, Deal $deal, int $baseVersion, array $data, User $actor): Deal
    {
        return $this->crm->transaction($company, function () use ($company, $deal, $baseVersion, $data, $actor) {
            $deal = $this->locked($company, $deal, $baseVersion);
            $old = $this->values($deal);
            $deal->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if (array_key_exists('extra', $data)) {
                $deal->extra = $this->fields->apply($company, 'deal', (array) $data['extra'], (array) ($deal->extra ?? []), false) ?: null;
            }
            $deal->version++;
            $deal->save();
            $this->audit->record('crm.deal_updated', $deal, old: $old, new: $this->values($deal), actor: $actor, organizationId: $company->getKey());

            return $deal;
        });
    }

    /** Into another stage of its pipeline: won or lost closes it; an open stage opens it again. */
    public function move(Organization $company, Deal $deal, int $baseVersion, string $stageId, ?string $lostReason, User $actor): Deal
    {
        return $this->crm->transaction($company, function () use ($company, $deal, $baseVersion, $stageId, $lostReason, $actor) {
            $deal = $this->locked($company, $deal, $baseVersion);
            $stage = $this->stage($company, $deal->pipeline_id, $stageId);
            if ($stage->outcome === 'lost' && trim((string) $lostReason) === '') {
                throw ValidationException::withMessages(['lost_reason' => __('crm::crm.validation.lost_reason')]);
            }
            $old = $this->values($deal);
            $status = self::statusOf($stage);
            $deal->forceFill([
                'stage_id' => $stage->getKey(), 'status' => $status, 'lost_reason' => $status === Deal::LOST ? trim((string) $lostReason) : null,
                'closed_at' => $status === Deal::OPEN ? null : ($deal->status === $status ? $deal->closed_at : now()), 'version' => $deal->version + 1,
            ])->save();
            $this->audit->record('crm.deal_moved', $deal, old: $old, new: $this->values($deal), actor: $actor, organizationId: $company->getKey());
            // Won now (not before): other modules may act on it.
            if ($status === Deal::WON && $old['status'] !== Deal::WON) {
                $key = (string) $this->crm->query(Pipeline::class, $company)->whereKey($deal->pipeline_id)->value('key');
                $event = new DealWon($company->getKey(), $deal->unit_id, $deal->getKey(), $deal->contact_id, $key, $actor->getKey());
                DB::afterCommit(fn () => event($event));
            }

            return $deal;
        });
    }

    /** Won (an accepted quotation): into the pipeline's won stage, once. */
    public function win(Organization $company, string $dealId, User $actor): void
    {
        $deal = $this->crm->query(Deal::class, $company)->whereKey($dealId)->first();
        if ($deal === null || $deal->status === Deal::WON) {
            return;
        }
        $this->move($company, $deal, $deal->version, $this->pipelines->closing($company, $deal->pipeline_id, 'won')->getKey(), null, $actor);
    }

    private function contact(Organization $company, string $id): Contact
    {
        $contact = $this->crm->query(Contact::class, $company)->whereKey($id)->first();
        if ($contact === null) {
            throw ValidationException::withMessages(['contact_id' => __('crm::crm.validation.contact')]);
        }
        if ($contact->anonymized_at !== null) {
            throw CrmException::anonymized();
        }

        return $contact;
    }

    private function stage(Organization $company, string $pipelineId, string $id): Stage
    {
        $stage = $this->crm->query(Stage::class, $company)->whereKey($id)->where('pipeline_id', $pipelineId)->where('is_active', true)->first();
        if ($stage === null) {
            throw ValidationException::withMessages(['stage_id' => __('crm::crm.validation.stage')]);
        }

        return $stage;
    }

    private function locked(Organization $company, Deal $deal, int $baseVersion): Deal
    {
        /** @var Deal $fresh */
        $fresh = $this->crm->query(Deal::class, $company)->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw CrmException::versionConflict(['version' => $fresh->version, 'stage_id' => $fresh->stage_id]);
        }

        return $fresh;
    }

    private static function statusOf(Stage $stage): string
    {
        return ['won' => Deal::WON, 'lost' => Deal::LOST][$stage->outcome] ?? Deal::OPEN;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Deal $deal): array
    {
        return [...$deal->only(['contact_id', 'stage_id', 'title', 'value_minor', 'currency_code', 'owner_id', 'status', 'lost_reason']), 'expected_on' => $deal->expected_on?->toDateString()];
    }
}
