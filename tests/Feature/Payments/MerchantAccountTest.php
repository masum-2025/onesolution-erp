<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Payments\DecimalAmount;
use App\Platform\Payments\Events\MerchantAccountChangeApplied;
use App\Platform\Payments\Events\MerchantAccountChangeRequested;
use App\Platform\Payments\Events\PaymentCollected;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\MerchantAccount;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\PaymentCollectables;
use App\Platform\Payments\Services\CollectPayment;
use App\Platform\Payments\Services\MerchantAccounts;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\FixtureFeeCollectable;

/*
 * A client's own payment gateway accounts (Phase 6). A school connects its
 * own SSLCommerz store; parents' payments go there, checked with the
 * school's credentials, never the platform's. Every change waits for a
 * second person, or for the waiting time when there is nobody else.
 */

const CLIENT_STORE = 'sunrisestore01';
const CLIENT_PASSWORD = 'client-store-password';

beforeEach(function () {
    $this->w = tenancyWorld();
    platformRule('online_payments.gateways', ['sslcommerz'], 'BD');
    toggles()->enable($this->w->g1, 'online_payments', 'Test setup');
    toggles()->enable($this->w->g3, 'online_payments', 'Test setup');

    // The platform's own sandbox store, as in Phase 5C-2.
    config(['payments.sslcommerz.store_id' => 'teststore', 'payments.sslcommerz.store_password' => SSL_STORE_PASSWORD]);
    app()->forgetInstance(GatewayRegistry::class);

    $this->owner = createMember($this->w->c1, MembershipType::Owner);
    $this->withHeader('Origin', config('app.url'));
    fakeStores();

    FixtureFeeCollectable::$fees = [];
    FixtureFeeCollectable::$paid = [];
    app(PaymentCollectables::class)->register('online_payments', FixtureFeeCollectable::class);

    foreach ([$this->owner] as $user) {
        RateLimiter::clear('merchant-accounts:'.$user->id);
    }
});

/**
 * SSLCommerz sandbox that knows two stores (the platform's and the
 * school's): a request with any other store id or password is refused, the
 * way the real gateway refuses it.
 */
function fakeStores(): void
{
    Http::swap(new Factory(app('events')));

    Http::fake(function (HttpRequest $request) {
        $stores = ['teststore' => SSL_STORE_PASSWORD, CLIENT_STORE => CLIENT_PASSWORD];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $data = str_contains($request->url(), '/gwprocess/') ? $request->data() : $query;
        $known = ($stores[$data['store_id'] ?? ''] ?? null) === ($data['store_passwd'] ?? null);

        if (str_contains($request->url(), '/gwprocess/v4/api.php')) {
            return $known
                ? Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/test'.$data['tran_id'], 'sessionkey' => 'SK'.$data['tran_id']])
                : Http::response(['status' => 'FAILED', 'failedreason' => 'Store Credential Error Or Store is De-active']);
        }

        if (str_contains($request->url(), 'merchantTransIDvalidationAPI.php')) {
            return Http::response($known ? ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []] : ['APIConnect' => 'INVALID_REQUEST']);
        }

        if (str_contains($request->url(), 'validationserverAPI.php')) {
            if (! $known) {
                return Http::response(['status' => 'INVALID_TRANSACTION']);
            }
            $tranId = substr((string) ($query['val_id'] ?? ''), 4);
            $payment = Payment::query()->find($tranId);
            $amount = $payment === null ? '0.00' : DecimalAmount::fromMinor($payment->amount_minor, $payment->currency_code);

            return Http::response([
                'status' => 'VALID', 'tran_id' => $tranId, 'val_id' => $query['val_id'], 'amount' => $amount,
                'currency' => 'BDT', 'currency_type' => 'BDT', 'currency_amount' => $amount,
                'card_type' => 'BKASH-BKash', 'risk_level' => '0', 'store_id' => $query['store_id'],
            ]);
        }

        return Http::response('Unexpected', 500);
    });
}

function connectStore(object $test, ?User $user = null, array $overrides = []): TestResponse
{
    $user ??= $test->owner;

    return $test->asToken(orgToken($user, $test->w->c1))->postJson("/api/organizations/{$test->w->c1->id}/merchant-accounts", [
        'gateway' => 'sslcommerz',
        'label' => 'School fees',
        'mode' => 'sandbox',
        'credentials' => ['store_id' => CLIENT_STORE, 'store_password' => CLIENT_PASSWORD],
        'current_password' => 'password',
        ...$overrides,
    ]);
}

function merchantAccount(): MerchantAccount
{
    return MerchantAccount::query()->withoutGlobalScope(OrganizationScope::class)->firstOrFail();
}

/** A second person at the school who may manage payment accounts. */
function secondManager(object $test): User
{
    return staffWithRoles($test->w->c1, makeRole($test->w->c1, ['online_payments.manage'], 'Accountant'));
}

/** A connected and approved account at the school. */
function activeStore(object $test): MerchantAccount
{
    connectStore($test)->assertCreated();
    $manager = secondManager($test);
    $account = merchantAccount();
    $test->asToken(orgToken($manager, $test->w->c1))
        ->postJson("/api/organizations/{$test->w->c1->id}/merchant-accounts/{$account->id}/approve", ['base_version' => $account->version, 'current_password' => 'password'])
        ->assertOk();

    return $account->fresh();
}

it('connects a store after the gateway accepts it: encrypted, write-only, audited without secrets', function () {
    Event::fake([MerchantAccountChangeRequested::class]);

    $response = connectStore($this)->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.pending.hint', 'sunr***01')
        ->assertJsonPath('data.pending.mode', 'sandbox');

    expect(json_encode($response->json()))->not->toContain(CLIENT_PASSWORD);

    $row = DB::table('merchant_accounts')->first();
    expect($row->pending_credentials)->not->toContain(CLIENT_PASSWORD)->not->toContain(CLIENT_STORE)
        ->and(merchantAccount()->pending_credentials)->toBe(['store_id' => CLIENT_STORE, 'store_password' => CLIENT_PASSWORD])
        ->and($row->credentials)->toBeNull();

    $audit = AuditLog::query()->where('action', 'payments.merchant_account.connected')->firstOrFail();
    expect(json_encode([$audit->old_values, $audit->new_values]))->not->toContain(CLIENT_PASSWORD)
        ->and($audit->organization_id)->toBe($this->w->c1->id);

    Event::assertDispatched(MerchantAccountChangeRequested::class, fn ($event) => $event->actor->is($this->owner));
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'merchantTransIDvalidationAPI.php') && str_contains($request->url(), 'store_id='.CLIENT_STORE));
});

it('saves nothing when the gateway refuses the store details or the password is wrong', function () {
    connectStore($this, overrides: ['credentials' => ['store_id' => CLIENT_STORE, 'store_password' => 'nope']])
        ->assertStatus(422)->assertJsonPath('code', 'check_rejected_credentials');

    connectStore($this, overrides: ['current_password' => 'not-my-password'])->assertStatus(422);

    expect(DB::table('merchant_accounts')->count())->toBe(0);
});

it('validates input and rejects unknown fields', function () {
    connectStore($this, overrides: ['credentials' => ['store_id' => 'a b', 'store_password' => '']])
        ->assertStatus(422)->assertJsonValidationErrors(['credentials.store_id', 'credentials.store_password']);

    connectStore($this, overrides: ['organization_id' => $this->w->c4->id])->assertStatus(422)->assertJsonValidationErrors(['organization_id']);
    connectStore($this, overrides: ['mode' => 'production'])->assertStatus(422)->assertJsonValidationErrors(['mode']);
});

it('needs a second person to approve; the maker cannot approve their own change', function () {
    $manager = secondManager($this);
    connectStore($this)->assertCreated()->assertJsonPath('data.pending.activates_at', null);
    $account = merchantAccount();

    $this->asToken(orgToken($this->owner, $this->w->c1))
        ->postJson("/api/organizations/{$this->w->c1->id}/merchant-accounts/{$account->id}/approve", ['base_version' => $account->version, 'current_password' => 'password'])
        ->assertForbidden()->assertJsonPath('code', 'own_change');

    Event::fake([MerchantAccountChangeApplied::class]);
    $this->asToken(orgToken($manager, $this->w->c1))
        ->postJson("/api/organizations/{$this->w->c1->id}/merchant-accounts/{$account->id}/approve", ['base_version' => $account->version, 'current_password' => 'password'])
        ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.hint', 'sunr***01')->assertJsonPath('data.pending', null);

    $account->refresh();
    expect($account->credentials)->toBe(['store_id' => CLIENT_STORE, 'store_password' => CLIENT_PASSWORD])
        ->and($account->approved_by)->toBe($manager->id)
        ->and($account->canCollect())->toBeTrue();
    Event::assertDispatched(MerchantAccountChangeApplied::class);
    expect(AuditLog::query()->where('action', 'payments.merchant_account.approved')->exists())->toBeTrue();
});

it('with nobody else to approve, takes effect by itself after the wait (rule), checked again first', function () {
    platformRule('online_payments.single_approver_wait_hours', 12);

    connectStore($this)->assertCreated();
    $account = merchantAccount();
    expect($account->activates_at->diffInHours(CarbonImmutable::now(), true))->toBeGreaterThan(11.9);

    $accounts = app(MerchantAccounts::class);
    expect($accounts->applyDue(CarbonImmutable::now()->addHours(11)))->toBe(['applied' => 0, 'failed' => 0])
        ->and($account->fresh()->status)->toBe('pending');

    $this->travel(13)->hours();
    expect($accounts->applyDue(CarbonImmutable::now()))->toBe(['applied' => 1, 'failed' => 0])
        ->and($account->fresh()->status)->toBe('active')
        ->and($account->fresh()->approved_by)->toBeNull();
    expect(AuditLog::query()->where('action', 'payments.merchant_account.applied_after_wait')->exists())->toBeTrue();
});

it('keeps the approved details working while new ones wait; a rejected change changes nothing', function () {
    $account = activeStore($this);

    $this->asToken(orgToken($this->owner, $this->w->c1))
        ->patchJson("/api/organizations/{$this->w->c1->id}/merchant-accounts/{$account->id}", [
            'base_version' => $account->version,
            'mode' => 'sandbox',
            'credentials' => ['store_id' => 'teststore', 'store_password' => SSL_STORE_PASSWORD],
            'current_password' => 'password',
        ])->assertOk()->assertJsonPath('data.hint', 'sunr***01')->assertJsonPath('data.pending.hint', 'test***re');

    $account->refresh();
    expect($account->credentials['store_id'])->toBe(CLIENT_STORE)->and($account->canCollect())->toBeTrue();

    $manager = User::query()->whereKey($account->approved_by)->firstOrFail();
    $this->asToken(orgToken($manager, $this->w->c1))
        ->postJson("/api/organizations/{$this->w->c1->id}/merchant-accounts/{$account->id}/reject", ['base_version' => $account->version, 'reason' => 'Not ours'])
        ->assertOk()->assertJsonPath('data.pending', null)->assertJsonPath('data.status', 'active');

    expect($account->fresh()->credentials['store_id'])->toBe(CLIENT_STORE);
});

it('renames at once, refuses a stale version', function () {
    $account = activeStore($this);

    $this->asToken(orgToken($this->owner, $this->w->c1))
        ->patchJson("/api/organizations/{$this->w->c1->id}/merchant-accounts/{$account->id}", ['base_version' => $account->version, 'label' => 'Fees', 'current_password' => 'password'])
        ->assertOk()->assertJsonPath('data.label', 'Fees');

    $this->asToken(orgToken($this->owner, $this->w->c1))
        ->postJson("/api/organizations/{$this->w->c1->id}/merchant-accounts/{$account->id}/disable", ['base_version' => $account->version])
        ->assertStatus(409)->assertJsonPath('code', 'stale');
});

it('turns off at once and back on with the password', function () {
    $account = activeStore($this);
    $token = orgToken($this->owner, $this->w->c1);

    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/merchant-accounts/{$account->id}/disable", ['base_version' => $account->version])
        ->assertOk()->assertJsonPath('data.status', 'disabled');
    expect(app(MerchantAccounts::class)->collectingAccount($this->w->c1, 'BDT'))->toBeNull();

    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/merchant-accounts/{$account->id}/enable", ['base_version' => $account->version + 1, 'current_password' => 'wrong'])
        ->assertStatus(422);
    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/merchant-accounts/{$account->id}/enable", ['base_version' => $account->version + 1, 'current_password' => 'password'])
        ->assertOk()->assertJsonPath('data.status', 'active');
});

it('offers live accounts only where the platform allows them', function () {
    connectStore($this, overrides: ['mode' => 'live'])->assertStatus(422)->assertJsonPath('code', 'live_not_allowed');

    platformRule('online_payments.live_mode_allowed', true);
    connectStore($this, overrides: ['mode' => 'live'])->assertCreated()->assertJsonPath('data.pending.mode', 'live');
    Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://securepay.sslcommerz.com/'));
});

it('follows the gateway rule: a group can close a gateway for its companies', function () {
    $this->asToken(orgToken($this->owner, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}/merchant-accounts")
        ->assertOk()->assertJsonPath('data.gateways.0.key', 'sslcommerz')->assertJsonPath('data.gateways.0.fields.1', ['key' => 'store_password', 'secret' => true]);

    orgRule($this->w->g1, 'online_payments.gateways', []);

    $this->asToken(orgToken($this->owner, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}/merchant-accounts")
        ->assertOk()->assertJsonPath('data.gateways', []);
    connectStore($this)->assertStatus(422)->assertJsonPath('code', 'gateway_not_offered');
});

it('answers 403 while the module is off, and never deletes the account', function () {
    $account = activeStore($this);
    toggles()->disable($this->w->g1, 'online_payments', 'Test');

    $this->asToken(orgToken($this->owner, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}/merchant-accounts")->assertForbidden();
    expect(MerchantAccount::query()->withoutGlobalScope(OrganizationScope::class)->whereKey($account->id)->exists())->toBeTrue();

    // G2's company never had it on.
    $owner3 = createMember($this->w->c3, MembershipType::Owner);
    $this->asToken(orgToken($owner3, $this->w->c3))->getJson("/api/organizations/{$this->w->c3->id}/merchant-accounts")->assertForbidden();
});

it('needs online_payments.view to see and online_payments.manage to act', function () {
    $outsider = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['crm.view'], 'Sales'));
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['online_payments.view'], 'Viewer'));

    $this->asToken(orgToken($outsider, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}/merchant-accounts")->assertForbidden();
    $this->asToken(orgToken($viewer, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}/merchant-accounts")
        ->assertOk()->assertJsonPath('data.can_manage', false);
    connectStore($this, $viewer)->assertForbidden();
});

it('serves branches from the company account; branch-only staff cannot change it', function () {
    activeStore($this);
    $branchManager = staffWithRoles($this->w->b1, makeRole($this->w->b1, ['online_payments.manage', 'online_payments.view'], 'Branch'));

    $this->asToken(orgToken($this->owner, $this->w->c1))->getJson("/api/organizations/{$this->w->b1->id}/merchant-accounts")
        ->assertOk()->assertJsonPath('data.inherited', true)->assertJsonPath('data.company.id', $this->w->c1->id)->assertJsonCount(1, 'data.accounts');

    $this->asToken(orgToken($branchManager, $this->w->b1))->getJson("/api/organizations/{$this->w->b1->id}/merchant-accounts")->assertForbidden();
});

it('keeps accounts inside their company and partner', function () {
    $account = activeStore($this);
    $owner2 = createMember($this->w->c2, MembershipType::Owner);
    $owner4 = createMember($this->w->c4, MembershipType::Owner);

    // Another company of the same group, and a company of another partner: not found.
    $this->asToken(orgToken($owner2, $this->w->c2))->getJson("/api/organizations/{$this->w->c1->id}/merchant-accounts")->assertNotFound();
    $this->asToken(orgToken($owner4, $this->w->c4))->getJson("/api/organizations/{$this->w->c1->id}/merchant-accounts")->assertNotFound();
    // Naming C1's account under their own company does not reach it either.
    $this->asToken(orgToken($owner2, $this->w->c2))
        ->postJson("/api/organizations/{$this->w->c2->id}/merchant-accounts/{$account->id}/disable", ['base_version' => $account->version])
        ->assertNotFound();

    $this->asToken(orgToken($owner4, $this->w->c4))->getJson("/api/organizations/{$this->w->c4->id}/merchant-accounts")
        ->assertOk()->assertJsonCount(0, 'data.accounts');
    expect($account->fresh()->status)->toBe('active');
});

it('portal members (parents) cannot reach payment accounts', function () {
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    $parent = User::factory()->create();
    app(AddMember::class)->handle($this->w->c1, $parent, MembershipType::Portal, AccessScope::Own);

    $this->asToken(orgToken($parent, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}/merchant-accounts")
        ->assertForbidden()->assertJsonPath('code', 'portal_only');
});

// ── Customers paying the school (collections) ─────────────────────────

function owedFee(object $test, User $payer, int $amount = 250000): string
{
    $id = (string) Str::ulid();
    FixtureFeeCollectable::$fees[$id] = ['organization' => $test->w->c1->id, 'payers' => [$payer->id], 'amount' => $amount, 'currency' => 'BDT'];

    return $id;
}

it('takes a parent\'s fee into the school\'s own store and tells the module', function () {
    $account = activeStore($this);
    $parent = User::factory()->create(['email_verified_at' => now()]);
    $fee = owedFee($this, $parent);

    $payment = app(CollectPayment::class)->start($this->w->c1, $parent, 'school.fee', $fee, (string) Str::ulid());

    expect($payment->merchant_account_id)->toBe($account->id)
        ->and($payment->purpose)->toBe(Payment::COLLECTION)
        ->and($payment->amount_minor)->toBe(250000)
        ->and($payment->checkout_url)->toStartWith('https://sandbox.sslcommerz.com/');
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/gwprocess/') && $request['store_id'] === CLIENT_STORE && $request['total_amount'] === '2500.00');
    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/gwprocess/') && $request['store_id'] === 'teststore');

    Event::fake([PaymentCollected::class]);
    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: CLIENT_PASSWORD))->assertOk();

    expect($payment->fresh()->status)->toBe(Payment::SUCCEEDED)
        ->and(FixtureFeeCollectable::$paid)->toBe([$payment->id]);
    Event::assertDispatched(PaymentCollected::class);
    // Validated with the school's store, never the platform's.
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'validationserverAPI.php') && str_contains($request->url(), 'store_id='.CLIENT_STORE));

    // The browser goes back to the module's own screen.
    $this->post('/payments/sslcommerz/return/success', sslNotice($payment, password: CLIENT_PASSWORD))->assertRedirect("/portal/fees/{$fee}");
});

it('does not accept a school payment\'s notice signed with the platform\'s password', function () {
    activeStore($this);
    $parent = User::factory()->create();
    $payment = app(CollectPayment::class)->start($this->w->c1, $parent, 'school.fee', owedFee($this, $parent), (string) Str::ulid());

    $this->post('/payments/sslcommerz/notify', sslNotice($payment, 'FAILED', password: SSL_STORE_PASSWORD))->assertStatus(400);
    expect($payment->fresh()->status)->toBe(Payment::PENDING);
});

it('only lets a payer pay what the module says they owe, once per click', function () {
    activeStore($this);
    $parent = User::factory()->create();
    $stranger = User::factory()->create();
    $fee = owedFee($this, $parent);
    $collect = app(CollectPayment::class);

    expect(fn () => $collect->start($this->w->c1, $stranger, 'school.fee', $fee, (string) Str::ulid()))
        ->toThrow(PaymentException::class)
        ->and(fn () => $collect->start($this->w->c1, $parent, 'school.bus', $fee, (string) Str::ulid()))->toThrow(PaymentException::class);

    $opId = (string) Str::ulid();
    $first = $collect->start($this->w->c1, $parent, 'school.fee', $fee, $opId);
    expect($collect->start($this->w->c1, $parent, 'school.fee', $fee, $opId)->id)->toBe($first->id)
        ->and(fn () => $collect->start($this->w->c1, $stranger, 'school.fee', $fee, $opId))->toThrow(PaymentException::class);
});

it('never uses the platform\'s store for a client\'s customers', function () {
    $parent = User::factory()->create();
    $fee = owedFee($this, $parent);

    // No account yet, then one that is turned off.
    expect(fn () => app(CollectPayment::class)->start($this->w->c1, $parent, 'school.fee', $fee, (string) Str::ulid()))
        ->toThrow(fn (PaymentException $e) => expect($e->errorCode())->toBe('no_merchant_account'));

    $account = activeStore($this);
    $account->forceFill(['status' => MerchantAccount::DISABLED])->save();
    expect(fn () => app(CollectPayment::class)->start($this->w->c1, $parent, 'school.fee', $fee, (string) Str::ulid()))
        ->toThrow(PaymentException::class);

    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/gwprocess/'));
    expect(Payment::query()->count())->toBe(0);
});

it('stops new collections while the module is off, but still applies money already taken', function () {
    activeStore($this);
    $parent = User::factory()->create();
    $payment = app(CollectPayment::class)->start($this->w->c1, $parent, 'school.fee', owedFee($this, $parent), (string) Str::ulid());

    toggles()->disable($this->w->g1, 'online_payments', 'Test');

    expect(fn () => app(CollectPayment::class)->start($this->w->c1, $parent, 'school.fee', owedFee($this, $parent), (string) Str::ulid()))
        ->toThrow(fn (PaymentException $e) => expect($e->errorCode())->toBe('kind_not_available'));

    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: CLIENT_PASSWORD))->assertOk();
    expect($payment->fresh()->status)->toBe(Payment::SUCCEEDED)->and(FixtureFeeCollectable::$paid)->toBe([$payment->id]);
});
