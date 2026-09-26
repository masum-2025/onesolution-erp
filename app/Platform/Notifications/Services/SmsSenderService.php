<?php

namespace App\Platform\Notifications\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Models\PartnerSmsSender;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;

/**
 * A partner's SMS sender ID. Where operators require registration
 * (sms.sender_id_requires_approval), the platform approves it after
 * registering it; until then messages use the platform's sender ID.
 */
class SmsSenderService
{
    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts, private AuditLogger $audit) {}

    public function request(Partner $partner, string $senderId, User $actor): PartnerSmsSender
    {
        $needsApproval = (bool) $this->rules->get('sms.sender_id_requires_approval', $this->contexts->forPartner($partner));

        return DB::transaction(function () use ($partner, $senderId, $actor, $needsApproval) {
            $sender = PartnerSmsSender::query()->where('partner_id', $partner->getKey())->lockForUpdate()->first() ?? new PartnerSmsSender;
            $old = $sender->exists ? ['sender_id' => $sender->sender_id, 'status' => $sender->status] : [];

            $sender->forceFill([
                'partner_id' => $partner->getKey(),
                'sender_id' => $senderId,
                'status' => $needsApproval ? PartnerSmsSender::PENDING : PartnerSmsSender::APPROVED,
                'note' => null,
                'decided_at' => $needsApproval ? null : now(),
                'requested_by' => $actor->getKey(),
            ])->save();

            $this->audit->record(action: 'partner.sms_sender_requested', target: $sender, old: $old, new: ['sender_id' => $senderId, 'status' => $sender->status], actor: $actor, partnerId: $partner->getKey());

            return $sender;
        });
    }

    public function decide(PartnerSmsSender $sender, bool $approve, string $note, ?User $actor = null): PartnerSmsSender
    {
        $old = ['status' => $sender->status];
        $sender->forceFill([
            'status' => $approve ? PartnerSmsSender::APPROVED : PartnerSmsSender::REJECTED,
            'note' => $note,
            'decided_at' => now(),
        ])->save();

        $this->audit->record(
            action: $approve ? 'partner.sms_sender_approved' : 'partner.sms_sender_rejected',
            target: $sender,
            old: $old,
            new: ['sender_id' => $sender->sender_id, 'status' => $sender->status],
            reason: $note,
            actor: $actor,
            partnerId: $sender->partner_id,
        );

        return $sender;
    }

    public function remove(PartnerSmsSender $sender, User $actor): void
    {
        $this->audit->record(action: 'partner.sms_sender_removed', target: $sender, old: ['sender_id' => $sender->sender_id, 'status' => $sender->status], actor: $actor, partnerId: $sender->partner_id);
        $sender->delete();
    }
}
