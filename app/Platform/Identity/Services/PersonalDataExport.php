<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Billing\Models\TrialGrant;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Legal\Models\DocumentAcceptance;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Payments\Models\Payment;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\PartnerUser;
use Carbon\CarbonInterface;

/**
 * "Download my data" (Phase 5C-3): what the platform keeps about the person
 * themself, in one JSON file. The business data of their workspaces is the
 * organization's, exported from its own export screen (Phase 5B-2).
 */
class PersonalDataExport
{
    /** Most recent own actions included (older ones stay in the audit log). */
    private const AUDIT_LIMIT = 5000;

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $id = $user->getKey();

        return [
            'generated_at' => now()->toIso8601String(),
            'account' => [
                'id' => $id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $this->time($user->email_verified_at),
                'phone' => $user->phone,
                'phone_verified_at' => $this->time($user->phone_verified_at),
                'language' => $user->locale,
                'marketing_consent_at' => $this->time($user->marketing_consent_at),
                'created_at' => $this->time($user->created_at),
                'password_changed_at' => $this->time($user->password_changed_at),
                'deletion_due_at' => $this->time($user->deletion_due_at),
            ],
            'memberships' => OrganizationMembership::query()->with('organization')->where('user_id', $id)->get()
                ->map(fn (OrganizationMembership $membership) => [
                    'organization' => $membership->organization?->displayName(),
                    'organization_type' => $membership->organization?->type->value,
                    'membership_type' => $membership->membership_type->value,
                    'status' => $membership->status->value,
                    'since' => $this->time($membership->created_at),
                ])->values()->all(),
            'partner_memberships' => PartnerUser::query()->with('partner')->where('user_id', $id)->get()
                ->map(fn (PartnerUser $staff) => [
                    'partner' => $staff->partner?->name,
                    'role' => $staff->role->value,
                    'status' => $staff->status->value,
                ])->values()->all(),
            'devices' => UserSession::query()->where('user_id', $id)->orderByDesc('last_seen_at')->get()
                ->map(fn (UserSession $session) => [
                    'browser' => $session->browser,
                    'platform' => $session->platform,
                    'ip' => $session->ip,
                    'last_seen_at' => $this->time($session->last_seen_at),
                    'ended_at' => $this->time($session->revoked_at),
                ])->values()->all(),
            'legal_acceptances' => DocumentAcceptance::query()->where('accepted_by', $id)->orderBy('accepted_at')->get()
                ->map(fn (DocumentAcceptance $acceptance) => [
                    'document' => $acceptance->kind,
                    'version' => $acceptance->version,
                    'language' => $acceptance->locale,
                    'accepted_at' => $this->time($acceptance->accepted_at),
                ])->values()->all(),
            'payments' => Payment::query()->where('user_id', $id)->orderBy('created_at')->get()
                ->map(fn (Payment $payment) => [
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency_code,
                    'status' => $payment->status,
                    'method' => $payment->method,
                    'plan' => $payment->plan_key,
                    'created_at' => $this->time($payment->created_at),
                ])->values()->all(),
            'free_trials' => TrialGrant::query()->where('user_id', $id)->where('subject', 'like', 'user:%')->get()
                ->map(fn (TrialGrant $grant) => ['plan' => $grant->plan_key, 'started_at' => $this->time($grant->created_at)])
                ->values()->all(),
            'messages_sent_to_you' => NotificationDelivery::query()->where('user_id', $id)->orderBy('created_at')->get()
                ->map(fn (NotificationDelivery $delivery) => [
                    'message' => $delivery->notification_key,
                    'channel' => $delivery->channel,
                    'to' => $delivery->recipient,
                    'status' => $delivery->status,
                    'at' => $this->time($delivery->created_at),
                ])->values()->all(),
            'your_actions' => AuditLog::query()->where('actor_user_id', $id)->latest('created_at')->limit(self::AUDIT_LIMIT)->get()
                ->map(fn (AuditLog $entry) => [
                    'action' => $entry->action,
                    'at' => $this->time($entry->created_at),
                    'ip' => $entry->ip_address,
                ])->values()->all(),
        ];
    }

    private function time(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }
}
