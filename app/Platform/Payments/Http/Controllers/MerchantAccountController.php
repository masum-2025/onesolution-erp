<?php

namespace App\Platform\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Http\Requests\ChangeMerchantAccountRequest;
use App\Platform\Payments\Http\Requests\ConnectMerchantAccountRequest;
use App\Platform\Payments\Http\Requests\MerchantPasswordRequest;
use App\Platform\Payments\Http\Requests\MerchantReasonRequest;
use App\Platform\Payments\Models\MerchantAccount;
use App\Platform\Payments\Services\MerchantAccounts;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\LocalDate;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A client's own payment gateway accounts (Phase 6), at its company.
 * Seeing needs online_payments.view, acting online_payments.manage, both at
 * the company (a branch collects into its company's account). Credentials
 * are write-only: answers carry a hint, never a secret.
 */
class MerchantAccountController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private MerchantAccounts $accounts,
        private GatewayRegistry $gateways,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $requested = $this->findVisible($organization);
        $company = $this->company($requested, 'online_payments.view');
        $canManage = Gate::allows('online_payments.manage', $company);
        $user = $request->user();

        return response()->json(['data' => [
            'company' => ['id' => $company->getKey(), 'name' => $company->displayName()],
            // Opened from a branch or department: the company's accounts serve it.
            'inherited' => ! $requested->is($company),
            'currency' => $this->accounts->currencyOf($company),
            'gateways' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => __("payments.gateways.{$key}"),
                'currencies' => $this->gateways->driver($key)->currencies(),
                'fields' => array_map(
                    fn (string $field, array $definition) => ['key' => $field, 'secret' => $definition['secret']],
                    array_keys($this->gateways->driver($key)->credentialFields()),
                    $this->gateways->driver($key)->credentialFields(),
                ),
            ], $this->accounts->offered($company)),
            'live_allowed' => $this->accounts->liveAllowed($company),
            'wait_hours' => (int) $this->rules->get('online_payments.single_approver_wait_hours', $this->contexts->forOrganization($company)),
            // Nobody but me could approve my change: it would take effect after the wait.
            'only_approver' => $canManage && $this->accounts->approvers($company, $user)->isEmpty(),
            'can_manage' => $canManage,
            'accounts' => $this->accounts->accounts($company)->map(fn (MerchantAccount $account) => $this->present($account, $user, $canManage))->values(),
        ]]);
    }

    public function store(ConnectMerchantAccountRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($this->findVisible($organization), 'online_payments.manage');
        $data = $request->validated();

        $account = $this->accounts->connect(
            $company,
            $request->user(),
            $data['current_password'],
            $data['gateway'],
            $data['label'],
            $data['mode'],
            $data['credentials'],
        );

        return response()->json([
            'data' => $this->present($account, $request->user(), true),
            'message' => $this->requestedMessage($account),
        ], 201);
    }

    public function update(ChangeMerchantAccountRequest $request, string $organization, string $account): JsonResponse
    {
        [$company, $found] = $this->account($organization, $account);
        $data = $request->validated();

        $found = $this->accounts->change(
            $company,
            $found,
            $request->user(),
            $data['current_password'],
            (int) $data['base_version'],
            $data['label'] ?? null,
            $data['mode'] ?? null,
            $data['credentials'] ?? null,
        );

        return response()->json([
            'data' => $this->present($found, $request->user(), true),
            'message' => isset($data['credentials']) ? $this->requestedMessage($found) : __('payments.messages.merchant_renamed'),
        ]);
    }

    public function approve(MerchantPasswordRequest $request, string $organization, string $account): JsonResponse
    {
        [, $found] = $this->account($organization, $account);
        $found = $this->accounts->approve($found, $request->user(), $request->validated('current_password'), (int) $request->validated('base_version'));

        return response()->json(['data' => $this->present($found, $request->user(), true), 'message' => __('payments.messages.merchant_approved')]);
    }

    public function reject(MerchantReasonRequest $request, string $organization, string $account): JsonResponse
    {
        [, $found] = $this->account($organization, $account);
        $found = $this->accounts->reject($found, $request->user(), (int) $request->validated('base_version'), $request->validated('reason'));

        return response()->json(['data' => $this->present($found, $request->user(), true), 'message' => __('payments.messages.merchant_rejected')]);
    }

    public function disable(MerchantReasonRequest $request, string $organization, string $account): JsonResponse
    {
        [, $found] = $this->account($organization, $account);
        $found = $this->accounts->disable($found, $request->user(), (int) $request->validated('base_version'), $request->validated('reason'));

        return response()->json(['data' => $this->present($found, $request->user(), true), 'message' => __('payments.messages.merchant_disabled')]);
    }

    public function enable(MerchantPasswordRequest $request, string $organization, string $account): JsonResponse
    {
        [$company, $found] = $this->account($organization, $account);
        $found = $this->accounts->enable($company, $found, $request->user(), $request->validated('current_password'), (int) $request->validated('base_version'));

        return response()->json(['data' => $this->present($found, $request->user(), true), 'message' => __('payments.messages.merchant_enabled')]);
    }

    public function test(Request $request, string $organization, string $account): JsonResponse
    {
        [, $found] = $this->account($organization, $account);
        $result = $this->accounts->test($found);

        if ($result !== 'ok') {
            throw PaymentException::checkFailed($result);
        }

        return response()->json(['data' => $this->present($found, $request->user(), true), 'message' => __('payments.messages.merchant_check_ok')]);
    }

    /**
     * @return array{0: Organization, 1: MerchantAccount}
     */
    private function account(string $organization, string $account): array
    {
        $company = $this->company($this->findVisible($organization), 'online_payments.manage');

        return [$company, $this->accounts->find($company, $account)];
    }

    private function company(Organization $organization, string $permission): Organization
    {
        $company = $this->accounts->companyOf($organization) ?? throw PaymentException::notCompany();

        Gate::authorize($permission, $company);

        return $company->loadMissing('partner');
    }

    private function requestedMessage(MerchantAccount $account): string
    {
        return $account->activates_at === null
            ? __('payments.messages.merchant_requested')
            : __('payments.messages.merchant_requested_alone', ['date' => LocalDate::format($account->activates_at)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MerchantAccount $account, User $user, bool $canManage): array
    {
        $pendingBy = $account->pending_by === null ? null : User::query()->find($account->pending_by);

        return [
            'id' => $account->getKey(),
            'gateway' => $account->gateway,
            'gateway_label' => __("payments.gateways.{$account->gateway}"),
            'label' => $account->label,
            'status' => $account->status,
            'mode' => $account->mode,
            'hint' => $account->credential_hint,
            'currency' => $account->currency_code,
            'approved_at' => $account->approved_at?->toIso8601String(),
            'pending' => $account->hasPendingChange() ? [
                'mode' => $account->pending_mode,
                'hint' => $account->pending_hint,
                'by' => $pendingBy?->name,
                'by_me' => $account->pending_by === $user->getKey(),
                'at' => $account->pending_at?->toIso8601String(),
                'activates_at' => $account->activates_at?->toIso8601String(),
            ] : null,
            'check_result' => $account->check_result,
            'checked_at' => $account->checked_at?->toIso8601String(),
            'version' => $account->version,
            'can_approve' => $canManage && $account->hasPendingChange() && $account->pending_by !== $user->getKey(),
        ];
    }
}
