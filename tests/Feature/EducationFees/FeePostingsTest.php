<?php

use App\Models\User;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\MerchantAccount;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\Services\CollectPayment;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Period;
use Modules\EducationFees\Models\Posting;
use Modules\EducationFees\Models\Receipt;

/*
 * FEE-2b: fees in the books (school chart) through Accounting's Ledger,
 * after the counter, never blocking it; tried again when the books refused;
 * and families paying a bill online from the portal.
 */

const FEE_STORE = 'feestore01';
const FEE_STORE_PASSWORD = 'fee-store-password';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-05 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach (['education', 'education_fees', 'accounting', 'client_portal', 'online_payments'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->checkerUser = createMember($this->w->c1);
    $this->checker = orgToken($this->checkerUser, $this->w->c1);
    orgRule($this->w->c1, 'accounting.chart_template', 'school');
    setUpBooks($this, $this->token, $this->w->c1)->assertCreated();

    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->fees = fn (string $path) => "/api/organizations/{$this->w->c1->id}/fees/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->school = eduSchool($this);
    $this->rahim = newStudent($this, $this->school, ['name' => 'Rahim'])->assertCreated()->json('data');
    $head = fn (string $code, string $key, array $extra = []) => ($this->as)()->postJson(($this->fees)('heads'), ['code' => $code, 'name' => ['en' => $code], 'frequency' => 'monthly', 'income_key' => $key, ...$extra])->json('data');
    $this->tuition = $head('TUI', 'education_fees.tuition_income');
    $this->transport = $head('TRN', 'education_fees.transport_income');
    $structure = ($this->as)()->postJson(($this->fees)('structures'), ['name' => 'Fees', 'session_id' => $this->school->session['id'], 'lines' => [
        ['head_id' => $this->tuition['id'], 'amount_minor' => 150000], ['head_id' => $this->transport['id'], 'amount_minor' => 50000],
    ]])->json('data');
    ($this->as)()->postJson(($this->fees)("structures/{$structure['id']}/activate"), ['base_version' => $structure['version']])->assertOk();
    $this->month = function (string $period) {
        $run = ($this->as)()->postJson(($this->fees)('runs'), ['kind' => 'monthly', 'session_id' => $this->school->session['id'], 'period' => $period, 'issue_date' => "{$period}-01"])->assertCreated()->json('data');
        ($this->as)()->postJson(($this->fees)("runs/{$run['id']}/finalize"), ['base_version' => $run['version']])->assertOk();

        return ($this->as)()->getJson(($this->fees)("bills?run_id={$run['id']}"))->json('data.0');
    };
    $this->pay = fn (int $amount, array $extra = []) => ($this->as)()->postJson(($this->fees)('receipts'), ['student_id' => $this->rahim['id'], 'amount_minor' => $amount, 'method' => 'cash', 'received_on' => '2026-08-05', ...$extra]);
    // Each account's balance in the books (debit positive), by code.
    $this->balance = fn (string $code) => (int) JournalLine::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $this->w->c1->id)->where('account_id', accountId($this->w->c1, $code))->get()->sum(fn ($line) => $line->debit_minor - $line->credit_minor);
});

it('puts bills, discounts, receipts, advances, fines, voids, cancels and refunds in the books, balanced', function () {
    $concession = ($this->as)()->postJson(($this->fees)('concessions'), ['student_id' => $this->rahim['id'], 'head_id' => $this->tuition['id'], 'mode' => 'percent', 'percent_bp' => 2000, 'reason' => 'Merit', 'starts_on' => '2026-08-01'])->json('data');
    ($this->as)($this->checker)->postJson(($this->fees)("concessions/{$concession['id']}/approve"), ['base_version' => $concession['version']])->assertOk();
    $august = ($this->month)('2026-08');

    // Bill: 1 500.00 tuition less 20 % + 500.00 transport = 1 700.00 owed.
    expect(($this->balance)('1145'))->toBe(170000)->and(($this->balance)('4190'))->toBe(30000)
        ->and(($this->balance)('4110'))->toBe(-150000)->and(($this->balance)('4140'))->toBe(-50000);
    expect(($this->as)()->getJson(($this->fees)("bills/{$august['id']}"))->json('data.id'))->toBe($august['id']);

    // 2 000.00 cash: 1 700.00 meets the bill, 300.00 waits as an advance.
    $receipt = ($this->pay)(200000)->assertCreated()->json('data');
    expect(($this->balance)('1110'))->toBe(200000)->and(($this->balance)('1145'))->toBe(0)->and(($this->balance)('2150'))->toBe(-30000);
    expect(Receipt::query()->withoutGlobalScopes()->findOrFail($receipt['id'])->journal_id)->not->toBeNull();

    // September uses the advance first.
    ($this->month)('2026-09');
    expect(($this->balance)('2150'))->toBe(0)->and(($this->balance)('1145'))->toBe(140000);

    // A late fine, then waived.
    trustedOrgRule($this->w->c1, 'education_fees.late_fine', ['enabled' => true, 'mode' => 'once', 'amount_minor' => 5000, 'grace_days' => 0, 'max_minor' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-09-15 04:00:00', 'UTC'));
    $this->artisan('education-fees:apply-fines')->assertSuccessful();
    expect(($this->balance)('4150'))->toBe(-5000)->and(($this->balance)('1145'))->toBe(145000);
    // Tokens expire over weeks: fresh ones after the jump.
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->checker = orgToken($this->checkerUser, $this->w->c1);
    $september = ($this->as)()->getJson(($this->fees)('bills?status=open'))->json('data.0');
    ($this->as)($this->checker)->postJson(($this->fees)("bills/{$september['id']}/waive-fine"), ['reason' => 'Flood', 'base_version' => $september['version']])->assertOk();
    expect(($this->balance)('4150'))->toBe(0)->and(($this->balance)('1145'))->toBe(140000);

    // Cancelling September: its income undone; what the advance paid on it goes back to the advance.
    $september = ($this->as)()->getJson(($this->fees)("bills/{$september['id']}"))->json('data');
    ($this->as)()->postJson(($this->fees)("bills/{$september['id']}/cancel"), ['reason' => 'Left', 'base_version' => $september['version']])->assertOk();
    expect(($this->balance)('1145'))->toBe(0)->and(($this->balance)('2150'))->toBe(-30000)->and(($this->balance)('4110'))->toBe(-150000);

    // Voiding August's receipt: the cash goes, August is owed again, the advance it left goes too.
    trustedOrgRule($this->w->c1, 'education_fees.void_needs_second_person', false);
    ($this->as)()->postJson(($this->fees)("receipts/{$receipt['id']}/void"), ['reason' => 'Bounced'])->assertCreated()->assertJsonPath('data.status', 'approved');
    expect(($this->balance)('1110'))->toBe(0)->and(($this->balance)('1145'))->toBe(170000)->and(($this->balance)('2150'))->toBe(0);

    // Paid again with 300.00 more, which is paid back.
    ($this->pay)(200000, ['received_on' => '2026-09-15'])->assertCreated();
    ($this->as)()->postJson(($this->fees)('refunds'), ['student_id' => $this->rahim['id'], 'amount_minor' => 30000, 'method' => 'cash', 'reason' => 'Leaving'])->assertCreated();
    expect(($this->balance)('2150'))->toBe(0)->and(($this->balance)('1110'))->toBe(170000)->and(($this->balance)('1145'))->toBe(0);

    // Every entry balances; every event reached the books once.
    $journals = Journal::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->w->c1->id)->where('source_module', 'education_fees')->get();
    foreach ($journals as $journal) {
        $lines = JournalLine::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('journal_id', $journal->id)->get();
        expect((int) $lines->sum('debit_minor'))->toBe((int) $lines->sum('credit_minor'));
    }
    expect(Posting::query()->withoutGlobalScopes()->where('status', '!=', 'posted')->count())->toBe(0)
        ->and($journals)->toHaveCount(Posting::query()->withoutGlobalScopes()->whereNotNull('journal_id')->count());
});

it('never blocks the counter when the books refuse, and posts later', function () {
    // August is closed in the books: the bill and the receipt still happen.
    Period::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->w->c1->id)
        ->where('starts_on', '<=', '2026-08-05')->where('ends_on', '>=', '2026-08-05')->update(['status' => 'closed']);
    ($this->month)('2026-08');
    ($this->pay)(200000)->assertCreated();
    expect(Posting::query()->withoutGlobalScopes()->where('status', 'failed')->count())->toBe(2)
        ->and(Posting::query()->withoutGlobalScopes()->whereNotNull('error')->value('error'))->not->toBe('')
        ->and(($this->balance)('1110'))->toBe(0);

    Period::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $this->w->c1->id)->update(['status' => 'open']);
    $this->artisan('education-fees:post-pending')->assertSuccessful();
    $this->artisan('education-fees:post-pending')->assertSuccessful();
    expect(Posting::query()->withoutGlobalScopes()->where('status', 'posted')->count())->toBe(2)
        ->and(($this->balance)('1110'))->toBe(200000)->and(($this->balance)('2150'))->toBe(0);
});

it('posts nothing where the institution keeps no books', function () {
    toggles()->disable($this->w->g1, 'accounting', 'Test');
    ($this->month)('2026-08');
    ($this->pay)(100000)->assertCreated();
    expect(Posting::query()->withoutGlobalScopes()->count())->toBe(0);
});

function feeStore(object $test): void
{
    (new MerchantAccount)->forceFill([
        'organization_id' => $test->w->c1->id, 'gateway' => 'sslcommerz', 'label' => 'School store', 'currency_code' => 'BDT',
        'status' => MerchantAccount::ACTIVE, 'mode' => 'sandbox', 'credentials' => ['store_id' => FEE_STORE, 'store_password' => FEE_STORE_PASSWORD], 'version' => 1,
    ])->save();
    Http::fake(function (HttpRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if (str_contains($request->url(), '/gwprocess/')) {
            return Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/'.$request->data()['tran_id'], 'sessionkey' => 'SK1']);
        }
        if (str_contains($request->url(), 'validationserverAPI.php')) {
            $payment = Payment::query()->find(substr((string) ($query['val_id'] ?? ''), 4));
            $amount = number_format($payment->amount_minor / 100, 2, '.', '');

            return Http::response(['status' => 'VALID', 'tran_id' => $payment->id, 'val_id' => $query['val_id'], 'amount' => $amount, 'currency' => 'BDT', 'currency_type' => 'BDT', 'currency_amount' => $amount, 'risk_level' => '0', 'store_id' => FEE_STORE]);
        }

        return Http::response('Unexpected', 500);
    });
}

it('lets a linked parent pay a bill online, which becomes a receipt in the books', function () {
    platformRule('online_payments.gateways', ['sslcommerz'], 'BD');
    app()->forgetInstance(GatewayRegistry::class);
    orgRule($this->w->c1, 'client_portal.allow_online_payment', true);
    feeStore($this);
    $bill = ($this->month)('2026-08');
    $karim = newStudent($this, $this->school, ['name' => 'Karim', 'guardians' => [['name' => 'Other', 'phone' => '01711-999999', 'relation' => 'father']]])->assertCreated()->json('data');

    $parent = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $parent, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $parent->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $parent->id,
        'subject_type' => 'education.student', 'subject_id' => $this->rahim['id'], 'relation' => 'guardian', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();

    // Only a bill of a student the payer is linked to, for its whole balance.
    $collect = app(CollectPayment::class);
    $karimBill = ($this->as)()->getJson(($this->fees)("bills?student_id={$karim['id']}"))->json('data.0');
    expect(fn () => $collect->start($this->w->c1, $parent, 'education_fees.bill', $karimBill['id'] ?? (string) Str::ulid(), (string) Str::ulid()))->toThrow(Exception::class);
    $payment = $collect->start($this->w->c1, $parent, 'education_fees.bill', $bill['id'], (string) Str::ulid());
    expect($payment->amount_minor)->toBe(200000);

    // The counter takes 500.00 meanwhile: the online money meets the rest, and the extra waits as an advance.
    ($this->pay)(50000)->assertCreated();
    $this->withHeader('Origin', config('app.url'));
    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: FEE_STORE_PASSWORD))->assertOk();
    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: FEE_STORE_PASSWORD))->assertOk();

    $online = Receipt::query()->withoutGlobalScopes()->where('method', 'online')->sole();
    expect($online->only(['amount_minor', 'payment_id', 'collected_by', 'op_id']))->toMatchArray(['amount_minor' => 200000, 'payment_id' => $payment->id, 'collected_by' => null, 'op_id' => "payment-{$payment->id}"]);
    $statement = ($this->as)()->getJson(($this->fees)("students/{$this->rahim['id']}"))->json('data');
    expect($statement)->toMatchArray(['owed_minor' => 0, 'advance_minor' => 50000]);
    expect(($this->balance)('1130'))->toBe(200000)->and(($this->balance)('2150'))->toBe(-50000);

    orgRule($this->w->c1, 'client_portal.allow_online_payment', false);
    $next = ($this->month)('2026-09');
    expect(fn () => $collect->start($this->w->c1, $parent, 'education_fees.bill', $next['id'], (string) Str::ulid()))->toThrow(Exception::class);
});

it('shows a family their own child\'s fees in the portal, pays a bill and comes back to it', function () {
    platformRule('online_payments.gateways', ['sslcommerz'], 'BD');
    app()->forgetInstance(GatewayRegistry::class);
    orgRule($this->w->c1, 'client_portal.allow_online_payment', true);
    feeStore($this);
    $karim = newStudent($this, $this->school, ['name' => 'Karim', 'guardians' => [['name' => 'Other', 'phone' => '01711-999999', 'relation' => 'father']]])->assertCreated()->json('data');
    ($this->month)('2026-08');
    $august = ($this->as)()->getJson(($this->fees)("bills?student_id={$this->rahim['id']}"))->json('data.0');
    $karimBill = ($this->as)()->getJson(($this->fees)("bills?student_id={$karim['id']}"))->json('data.0');
    ($this->pay)(50000)->assertCreated();

    $parent = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $parent, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $parent->id)->where('organization_id', $this->w->c1->id)->sole();
    $link = new PortalLink;
    $link->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $parent->id,
        'subject_type' => 'education.student', 'subject_id' => $this->rahim['id'], 'relation' => 'mother', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $portal = orgToken($parent, $this->w->c1);

    // From the child's record to the fees page.
    $this->asToken($portal)->getJson("/api/portal/records/{$link->id}")->assertOk()->assertJsonFragment(['to' => "/portal/fees/{$link->id}"]);
    $view = $this->asToken($portal)->getJson("/api/portal/fees?record={$link->id}")->assertOk()->json('data');
    expect($view)->toMatchArray(['owed_minor' => 150000, 'advance_minor' => 0, 'currency' => 'BDT'])
        ->and($view['bills'][0])->toMatchArray(['id' => $august['id'], 'balance_minor' => 150000, 'can_pay' => true])
        ->and($view['receipts'])->toHaveCount(1);
    $bill = $this->asToken($portal)->getJson("/api/portal/fees/bills/{$august['id']}?record={$link->id}")->assertOk()->json('data');
    expect($bill['lines'])->toHaveCount(2)->and($bill['payments'][0]['amount_minor'])->toBe(50000)->and(json_encode($bill))->not->toContain('basis');
    $receipt = $this->asToken($portal)->getJson("/api/portal/fees/receipts/{$view['receipts'][0]['id']}?record={$link->id}")->assertOk()->json('data');
    expect($receipt)->toMatchArray(['amount_minor' => 50000, 'collector' => null]);

    // Another child's bill, without or with a stranger's record: the same not found; staff API stays closed.
    $this->asToken($portal)->getJson("/api/portal/fees/bills/{$karimBill['id']}?record={$link->id}")->assertNotFound();
    $this->asToken($portal)->postJson("/api/portal/fees/bills/{$karimBill['id']}/pay?record={$link->id}", ['op_id' => 'x1'])->assertNotFound();
    $this->asToken($portal)->getJson('/api/portal/fees?record='.Str::ulid())->assertNotFound();
    $this->asToken($portal)->getJson(($this->fees)('bills'))->assertForbidden();

    // Paying online: the amount comes from the bill; back to the child's fees after the gateway.
    $start = $this->asToken($portal)->postJson("/api/portal/fees/bills/{$august['id']}/pay?record={$link->id}", ['op_id' => 'tap-1', 'amount_minor' => 1])->assertCreated()->json('data');
    expect($start['amount_minor'])->toBe(150000);
    $payment = Payment::query()->findOrFail($start['payment_id']);
    $this->withHeader('Origin', config('app.url'));
    $this->post('/payments/sslcommerz/notify', sslNotice($payment, password: FEE_STORE_PASSWORD))->assertOk();
    $this->post('/payments/sslcommerz/return/success', sslNotice($payment, password: FEE_STORE_PASSWORD))->assertRedirect("/portal/fees/{$link->id}?paid={$payment->id}");
    $after = $this->asToken($portal)->getJson("/api/portal/fees?record={$link->id}")->json('data');
    expect($after['owed_minor'])->toBe(0)->and($after['bills'][0])->toMatchArray(['status' => 'paid', 'can_pay' => false]);
});
