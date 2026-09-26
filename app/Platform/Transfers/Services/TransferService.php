<?php

namespace App\Platform\Transfers\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Modules\Events\ModuleEnabled;
use App\Platform\Modules\ModuleCache;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Packaging\Actions\PreviewResult;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Partners\Services\HostResolver;
use App\Platform\Partners\Services\PartnerClientService;
use App\Platform\Rules\RuleCache;
use App\Platform\SupportAccess\Enums\GrantStatus;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Transfers\Events\ClientTransferRequested;
use App\Platform\Transfers\Events\ClientTransferred;
use App\Platform\Transfers\Exceptions\TransferException;
use App\Platform\Transfers\Models\ClientTransfer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves a client (its top organization and everything under it, with all
 * its data, members, settings and history) to another partner.
 *
 * The client decides: its top owner asks with the new partner's one-time
 * code (or asks to come back to the house partner) and consents to what the
 * preview shows; the new partner accepts; the old partner is told but cannot
 * block it. Past invoices and commissions stay with the old partner; its
 * partner plan, the client's domain at its address and any open support
 * access end. The new partner's governance and modules apply from then on.
 */
class TransferService
{
    public function __construct(
        private TransferCodes $codes,
        private PartnerClientService $clients,
        private SubscriptionService $subscriptions,
        private ModuleResolver $modules,
        private ModuleRegistry $registry,
        private ModuleCache $moduleCache,
        private RuleCache $ruleCache,
        private HostResolver $hosts,
        private AuditLogger $audit,
    ) {}

    /**
     * Who the code (or "house") points to, checked.
     */
    public function destination(Organization $root, ?string $code, bool $toHouse): Partner
    {
        $partner = $toHouse
            ? Partner::query()->where('is_house', true)->first()
            : $this->codeOwner($code);

        if ($partner === null) {
            throw TransferException::invalidCode();
        }

        if ($partner->getKey() === $root->partner_id) {
            throw TransferException::samePartner();
        }

        if (! $partner->isActive()) {
            throw TransferException::destinationInactive();
        }

        return $partner;
    }

    /**
     * What the move would change, without changing anything.
     *
     * @return array<string, mixed>
     */
    public function preview(Organization $root, Partner $to): array
    {
        $problems = $this->problems($root, $to);

        try {
            DB::transaction(function () use ($root, $to, $problems) {
                $before = $this->moduleStates($root);
                $this->movePartner($root, $to);
                $after = $this->moduleStates($root->fresh());

                throw new PreviewResult($this->summary($root, $to, $before, $after, $problems));
            });
        } catch (PreviewResult $result) {
            return $result->summary;
        } finally {
            $this->moduleCache->flushTree($root->getKey());
            $this->ruleCache->flushTree($root->getKey());
        }

        throw new RuntimeException('Transfer preview did not finish.');
    }

    /**
     * The client's owner asks to move, having seen the preview.
     */
    public function request(Organization $root, ?string $code, bool $toHouse, string $reason, User $owner): ClientTransfer
    {
        $to = $this->destination($root, $code, $toHouse);
        $summary = $this->preview($root, $to);
        $this->assertNoProblems($summary);

        $transfer = DB::transaction(function () use ($root, $to, $code, $toHouse, $reason, $owner, $summary) {
            Organization::query()->whereKey($root->getKey())->lockForUpdate()->first();

            if (ClientTransfer::query()->where('organization_id', $root->getKey())->where('status', ClientTransfer::AWAITING_PARTNER)->exists()) {
                throw TransferException::alreadyOpen();
            }

            $codeRecord = null;
            if (! $toHouse) {
                $codeRecord = $this->codes->find((string) $code);
                // Used once: a second client cannot move with the same code.
                $used = $codeRecord === null ? 0 : DB::table('transfer_codes')->where('id', $codeRecord->getKey())->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
                if ($used !== 1) {
                    throw TransferException::invalidCode();
                }
            }

            $transfer = new ClientTransfer;
            $transfer->forceFill([
                'organization_id' => $root->getKey(),
                'from_partner_id' => $root->partner_id,
                'to_partner_id' => $to->getKey(),
                'transfer_code_id' => $codeRecord?->getKey(),
                'status' => ClientTransfer::AWAITING_PARTNER,
                'reason' => $reason,
                'requested_by' => $owner->getKey(),
                'consented_at' => now(),
                'summary' => $summary,
            ])->save();

            $this->record('client.transfer_requested', $transfer, $owner, $reason);

            return $transfer;
        });

        // The house partner takes its clients back without a separate acceptance.
        if ($to->is_house) {
            return $this->complete($transfer, null, 'Returned to the house partner');
        }

        ClientTransferRequested::dispatch($transfer);

        return $transfer;
    }

    public function accept(ClientTransfer $transfer, User $actor, ?string $note = null): ClientTransfer
    {
        return $this->complete($transfer, $actor, $note);
    }

    public function reject(ClientTransfer $transfer, User $actor, string $note): ClientTransfer
    {
        return $this->close($transfer, ClientTransfer::REJECTED, $actor, $note, 'client.transfer_rejected');
    }

    public function cancel(ClientTransfer $transfer, User $actor): ClientTransfer
    {
        return $this->close($transfer, ClientTransfer::CANCELLED, $actor, null, 'client.transfer_cancelled');
    }

    /**
     * Platform operators move a client without its consent, e.g. when its
     * partner closes. The reason is recorded and everyone is told.
     */
    public function moveByPlatform(Organization $root, Partner $to, string $reason): ClientTransfer
    {
        if ($to->getKey() === $root->partner_id) {
            throw TransferException::samePartner();
        }

        $summary = $this->preview($root, $to);

        $transfer = new ClientTransfer;
        $transfer->forceFill([
            'organization_id' => $root->getKey(),
            'from_partner_id' => $root->partner_id,
            'to_partner_id' => $to->getKey(),
            'status' => ClientTransfer::AWAITING_PARTNER,
            'reason' => $reason,
            'by_platform' => true,
            'summary' => $summary,
        ])->save();

        return $this->complete($transfer, null, $reason, checkGovernance: false);
    }

    private function complete(ClientTransfer $transfer, ?User $actor, ?string $note, bool $checkGovernance = true): ClientTransfer
    {
        return DB::transaction(function () use ($transfer, $actor, $note, $checkGovernance) {
            $transfer = ClientTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();
            if (! $transfer->isOpen()) {
                throw TransferException::notOpen();
            }

            $root = Organization::query()->whereKey($transfer->organization_id)->lockForUpdate()->firstOrFail();
            $to = Partner::query()->whereKey($transfer->to_partner_id)->lockForUpdate()->firstOrFail();

            // It moved some other way meanwhile: this request no longer applies.
            if ($root->partner_id !== $transfer->from_partner_id) {
                throw TransferException::notOpen();
            }

            if ($checkGovernance) {
                $this->clients->assertCountryAllowed($to, $root->country_code);
                $this->clients->assertClientSlot($to);
            }

            $before = $this->moduleStates($root);
            $this->movePartner($root, $to);
            $root = $root->fresh();

            $this->subscriptions->for($root)->forceFill(['partner_id' => $to->getKey(), 'partner_plan_id' => null])->save();
            $this->endDomains($root, $transfer->from_partner_id);
            $this->endSupportAccess($root, $actor);

            $this->moduleCache->flushTree($root->getKey());
            $this->ruleCache->flushTree($root->getKey());
            $this->dispatchModuleChanges($root, $before, $this->moduleStates($root), $actor, $note ?? 'Moved to another provider');

            $transfer->forceFill([
                'status' => ClientTransfer::COMPLETED,
                'decided_by' => $actor?->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
                'completed_at' => now(),
            ])->save();

            // In the client's log and in both partners' logs.
            $this->record('client.transferred', $transfer, $actor, $note, partnerId: $transfer->from_partner_id);
            $this->record('client.transferred', $transfer, $actor, $note, partnerId: $transfer->to_partner_id);

            ClientTransferred::dispatch($transfer);

            return $transfer;
        });
    }

    private function close(ClientTransfer $transfer, string $status, User $actor, ?string $note, string $action): ClientTransfer
    {
        return DB::transaction(function () use ($transfer, $status, $actor, $note, $action) {
            $transfer = ClientTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();
            if (! $transfer->isOpen()) {
                throw TransferException::notOpen();
            }

            $transfer->forceFill(['status' => $status, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->record($action, $transfer, $actor, $note);

            return $transfer;
        });
    }

    private function codeOwner(?string $code): ?Partner
    {
        $record = $code === null ? null : $this->codes->find($code);

        return $record !== null && $record->isUsable() ? Partner::query()->find($record->partner_id) : null;
    }

    /**
     * The new partner's governance: things that would stop the move.
     *
     * @return list<string>
     */
    private function problems(Organization $root, Partner $to): array
    {
        $problems = [];
        foreach ([fn () => $this->clients->assertCountryAllowed($to, $root->country_code), fn () => $this->clients->assertClientSlot($to)] as $check) {
            try {
                $check();
            } catch (TenancyException $exception) {
                $problems[] = $exception->userMessage();
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function assertNoProblems(array $summary): void
    {
        if ($summary['problems'] !== []) {
            throw TransferException::blocked(implode(' ', $summary['problems']));
        }
    }

    /**
     * Every unit's partner changes at once; the tree itself does not.
     */
    private function movePartner(Organization $root, Partner $to): void
    {
        Organization::query()->where('root_id', $root->getKey())->increment('version', 1, ['partner_id' => $to->getKey()]);
    }

    /**
     * @return array<string, array<string, bool>> Enabled modules per unit.
     */
    private function moduleStates(Organization $root): array
    {
        $this->moduleCache->flushTree($root->getKey());

        return Organization::query()->where('root_id', $root->getKey())->orderBy('depth')->get()
            ->mapWithKeys(fn (Organization $node) => [$node->getKey() => array_map(fn ($module) => $module->enabled, $this->modules->fresh($node))])
            ->all();
    }

    /**
     * @param  array<string, array<string, bool>>  $before
     * @param  array<string, array<string, bool>>  $after
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function summary(Organization $root, Partner $to, array $before, array $after, array $problems): array
    {
        [$off, $on] = [[], []];
        foreach ($after as $node => $modules) {
            foreach ($modules as $key => $enabled) {
                if (($before[$node][$key] ?? false) !== $enabled) {
                    $enabled ? $on[$key] = true : $off[$key] = true;
                }
            }
        }

        $subscription = $this->subscriptions->for($root);
        $named = fn (array $keys) => array_values(array_map(fn (string $key) => ['key' => $key, 'name' => $this->registry->get($key)->label()], array_keys($keys)));
        $openInvoices = Invoice::query()->where('organization_id', $root->getKey())->where('type', Invoice::INVOICE)->where('status', Invoice::ISSUED);

        return [
            'to' => ['id' => $to->getKey(), 'name' => $to->name, 'house' => (bool) $to->is_house],
            'modules_off' => $named($off),
            'modules_on' => $named($on),
            'partner_plan_ends' => $subscription->partner_plan_id === null ? null : PartnerPlan::query()->find($subscription->partner_plan_id)?->label(),
            'domains_end' => PartnerDomain::query()->where('organization_id', $root->getKey())->where('status', '!=', DomainStatus::Disabled)->pluck('host')->all(),
            'support_access_ends' => $this->openGrants($root)->count(),
            'open_invoices' => $openInvoices->count(),
            'problems' => $problems,
        ];
    }

    private function endDomains(Organization $root, string $fromPartnerId): void
    {
        PartnerDomain::query()->where('organization_id', $root->getKey())->where('partner_id', $fromPartnerId)->get()
            ->each(function (PartnerDomain $domain) {
                $domain->forceFill(['status' => DomainStatus::Disabled])->save();
                $this->hosts->forget($domain->host);
            });
    }

    private function endSupportAccess(Organization $root, ?User $actor): void
    {
        foreach ($this->openGrants($root)->get() as $grant) {
            $grant->forceFill(['status' => GrantStatus::Revoked, 'ended_at' => now(), 'decision_reason' => 'The client moved to another provider.'])->save();
            $this->audit->record(
                action: 'support.revoked',
                target: $grant,
                new: ['grant' => $grant->getKey(), 'requested_by' => $grant->requested_by, 'by' => 'client_transfer'],
                actor: $actor,
                organizationId: $grant->organization_id,
                partnerId: $grant->partner_id,
            );
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SupportGrant>
     */
    private function openGrants(Organization $root)
    {
        return SupportGrant::query()
            ->whereIn('organization_id', Organization::query()->where('root_id', $root->getKey())->select('id'))
            ->where(fn ($query) => $query
                ->where('status', GrantStatus::Pending)
                ->orWhere(fn ($approved) => $approved->where('status', GrantStatus::Approved)->where('expires_at', '>', now())));
    }

    /**
     * One event per change, at the highest unit where it happens.
     *
     * @param  array<string, array<string, bool>>  $before
     * @param  array<string, array<string, bool>>  $after
     */
    private function dispatchModuleChanges(Organization $root, array $before, array $after, ?User $actor, string $reason): void
    {
        /** @var Collection<string, Organization> $nodes */
        $nodes = Organization::query()->where('root_id', $root->getKey())->get()->keyBy('id');

        foreach ($after as $nodeId => $modules) {
            $node = $nodes[$nodeId];
            foreach ($modules as $key => $enabled) {
                if (($before[$nodeId][$key] ?? false) === $enabled) {
                    continue;
                }

                $parentChanged = $node->parent_id !== null
                    && ($before[$node->parent_id][$key] ?? false) !== ($after[$node->parent_id][$key] ?? false)
                    && ($after[$node->parent_id][$key] ?? false) === $enabled;

                if (! $parentChanged) {
                    $enabled ? ModuleEnabled::dispatch($node, $key, $actor, $reason) : ModuleDisabled::dispatch($node, $key, $actor, $reason);
                }
            }
        }
    }

    private function record(string $action, ClientTransfer $transfer, ?User $actor, ?string $reason, ?string $partnerId = null): void
    {
        $this->audit->record(
            action: $action,
            target: $transfer,
            new: [
                'from_partner' => $transfer->from_partner_id,
                'to_partner' => $transfer->to_partner_id,
                'status' => $transfer->status,
                'by_platform' => (bool) $transfer->by_platform,
            ],
            reason: $reason,
            actor: $actor,
            organizationId: $transfer->organization_id,
            partnerId: $partnerId ?? $transfer->to_partner_id,
        );
    }
}
