<?php

namespace App\Platform\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Notifications\Exceptions\NotificationException;
use App\Platform\Notifications\Http\Requests\MailDomainRequest;
use App\Platform\Notifications\Http\Requests\MailSenderRequest;
use App\Platform\Notifications\Http\Requests\SmsSenderRequest;
use App\Platform\Notifications\Http\Requests\TestSmsRequest;
use App\Platform\Notifications\Models\PartnerMailDomain;
use App\Platform\Notifications\Models\PartnerSmsSender;
use App\Platform\Notifications\Services\MailDomainService;
use App\Platform\Notifications\Services\MailSender;
use App\Platform\Notifications\Services\Mask;
use App\Platform\Notifications\Services\SmsSender;
use App\Platform\Notifications\Services\SmsSenderService;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Partners\Http\Requests\PartnerReasonRequest;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner console: how its messages go out. The sending domain (with the
 * DNS records to publish), the sender name, the SMS sender ID, and test
 * messages. Everyone in the console sees it; owners change it.
 */
class PartnerMessagingController extends Controller
{
    use PartnerConsole;

    public function __construct(
        private MailDomainService $domains,
        private SmsSenderService $smsSenders,
        private MailSender $mail,
        private SmsSender $sms,
        private BrandResolver $brands,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->state()]);
    }

    public function storeDomain(MailDomainRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $this->domains->add($this->partner(), $request->validated('domain'), $request->user());

        return response()->json(['data' => $this->state(), 'message' => __('notifications.messages.domain_added')], 201);
    }

    public function verifyDomain(Request $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $domain = $this->domain();

        try {
            $this->domains->verify($domain, $request->user());
        } catch (NotificationException $exception) {
            // The check results are saved either way; show them with the reason.
            return response()->json(['data' => $this->state(), 'message' => $exception->userMessage(), 'code' => $exception->errorCode()], 422);
        }

        return response()->json(['data' => $this->state(), 'message' => __('notifications.messages.domain_verified')]);
    }

    public function updateSender(MailSenderRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $this->domains->updateSender($this->domain(), $request->validated(), $request->user());

        return response()->json(['data' => $this->state(), 'message' => __('notifications.messages.sender_saved')]);
    }

    public function destroyDomain(PartnerReasonRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $this->domains->remove($this->domain(), $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->state(), 'message' => __('notifications.messages.domain_removed')]);
    }

    /**
     * A test email to the person asking, exactly as clients would get it.
     */
    public function testEmail(Request $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $partner = $this->partner();
        $locale = app()->getLocale();
        $product = $this->brands->for($partner)['name'];

        $from = $this->mail->send(
            $partner,
            $request->user()->email,
            __('notifications.test.subject', ['product' => $product], $locale),
            __('notifications.test.body', ['product' => $product], $locale),
            null,
            $locale,
        );

        return response()->json(['message' => __('notifications.messages.test_email_sent', ['to' => Mask::email($request->user()->email), 'from' => $from])]);
    }

    public function storeSmsSender(SmsSenderRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $sender = $this->smsSenders->request($this->partner(), $request->validated('sender_id'), $request->user());

        return response()->json([
            'data' => $this->state(),
            'message' => __($sender->isApproved() ? 'notifications.messages.sender_id_approved' : 'notifications.messages.sender_id_requested'),
        ], 201);
    }

    public function destroySmsSender(Request $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $sender = PartnerSmsSender::query()->where('partner_id', $this->partner()->getKey())->first() ?? throw NotificationException::noSmsSender();
        $this->smsSenders->remove($sender, $request->user());

        return response()->json(['data' => $this->state(), 'message' => __('notifications.messages.sender_id_removed')]);
    }

    public function testSms(TestSmsRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $partner = $this->partner();
        $product = $this->brands->for($partner)['name'];

        $sender = $this->sms->send($partner, $request->validated('phone'), __('notifications.test.sms', ['product' => $product]));

        return response()->json(['message' => __('notifications.messages.test_sms_sent', ['to' => Mask::phone($request->validated('phone')), 'sender' => $sender])]);
    }

    private function domain(): PartnerMailDomain
    {
        return PartnerMailDomain::query()->where('partner_id', $this->partner()->getKey())->first() ?? throw NotificationException::noMailDomain();
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $partner = $this->partner();
        $context = $this->contexts->forPartner($partner);
        $domain = PartnerMailDomain::query()->where('partner_id', $partner->getKey())->first();
        $smsSender = PartnerSmsSender::query()->where('partner_id', $partner->getKey())->first();
        $sender = $this->mail->sender($partner);

        return [
            'mail' => [
                'from' => ['address' => $sender['address'], 'name' => $sender['name'], 'reply_to' => $sender['reply_to'], 'own_domain' => $sender['dkim'] !== null],
                'custom_domain_allowed' => (bool) $this->rules->get('mail.custom_domain_allowed', $context),
                'domain' => $domain === null ? null : [
                    'domain' => $domain->domain,
                    'status' => $domain->status,
                    // Always in the same order (JSON columns may reorder keys).
                    'checks' => array_combine(PartnerMailDomain::CHECKS, array_map(fn (string $check) => (bool) ($domain->checks[$check] ?? false), PartnerMailDomain::CHECKS)),
                    'records' => $domain->records(),
                    'local_part' => $domain->local_part,
                    'from_name' => $domain->from_name,
                    'reply_to' => $domain->reply_to,
                    'verified_at' => $domain->verified_at?->toIso8601String(),
                    'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
                ],
            ],
            'sms' => [
                'enabled' => $this->sms->enabled($partner),
                'sender_id' => $this->sms->senderId($partner),
                'own' => $smsSender === null ? null : [
                    'sender_id' => $smsSender->sender_id,
                    'status' => $smsSender->status,
                    'note' => $smsSender->note,
                ],
                'needs_approval' => (bool) $this->rules->get('sms.sender_id_requires_approval', $context),
            ],
            'can_edit' => $this->hasRole(PartnerUserRole::Owner),
        ];
    }
}
