<?php

use App\Platform\Notifications\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Diagnostics\Models\Commission;
use Modules\Diagnostics\Services\Commissions;

/*
 * H-2b Diagnostics: referring doctors' commission once the bill is final
 * (the doctor's percent, else the rule), taken back with a reason, paid up to
 * what is owed (op ids, books); time-limited report links that open without
 * signing in (verified values only, expired is 403, misses are 404), SMS by
 * hand or by rule; permissions, modules off, tenant and partner isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    setPlan($this->w->g1, 'business');
    foreach (['patients', 'patient_billing', 'diagnostics'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->ownerUser = createMember($this->w->c1);
    $this->owner = orgToken($this->ownerUser, $this->w->c1);
    $role = fn (array $permissions, string $name) => orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, $permissions, $name)), $this->w->c1);
    $this->reception = $role(['diagnostics.view', 'diagnostics.order', 'diagnostics.deliver'], 'Lab reception');
    $this->tech = $role(['diagnostics.view', 'diagnostics.collect', 'diagnostics.result', 'diagnostics.verify'], 'Technologist');
    $this->pathologist = $role(['diagnostics.view', 'diagnostics.result', 'diagnostics.verify'], 'Pathologist');
    $this->accounts = $role(['diagnostics.view', 'diagnostics.commission'], 'Lab accounts');
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/diagnostics/{$path}";
    $this->org = fn (string $path) => "/api/organizations/{$this->w->c1->id}/{$path}";

    foreach ([['CBC', 50000], ['XRAY', 80000]] as [$code, $price]) {
        $this->asToken($this->owner)->postJson(($this->org)('patient-billing/services'), ['code' => $code, 'name' => ['en' => $code], 'category' => 'test', 'price_minor' => $price, 'is_active' => true])->assertCreated();
    }
    $this->cbc = $this->asToken($this->owner)->postJson(($this->api)('tests'), ['code' => 'CBC', 'name' => ['en' => 'Complete blood count'], 'department' => 'haematology', 'sample_type' => 'blood', 'service_code' => 'CBC', 'is_active' => true])->assertCreated()->json('data');
    $this->xray = $this->asToken($this->owner)->postJson(($this->api)('tests'), ['code' => 'XRAY', 'name' => ['en' => 'Chest X-ray'], 'department' => 'imaging', 'sample_type' => 'none', 'service_code' => 'XRAY', 'is_active' => true])->assertCreated()->json('data');
    $this->asToken($this->owner)->putJson(($this->api)("tests/{$this->cbc['id']}/parameters"), ['parameters' => [
        ['name' => ['en' => 'Haemoglobin'], 'unit' => 'g/dL', 'result_type' => 'number', 'decimals' => 1, 'ranges' => [['sex' => 'any', 'low' => '12', 'high' => '17']]],
    ]])->assertOk();
    $this->asToken($this->owner)->putJson(($this->api)("tests/{$this->xray['id']}/parameters"), ['parameters' => [
        ['name' => ['en' => 'Findings'], 'result_type' => 'text', 'ranges' => [['sex' => 'any', 'normal_text' => 'Normal']]],
    ]])->assertOk();
    $this->rahim = $this->asToken($this->owner)->postJson(($this->org)('patients/patients'), ['name' => 'Rahim Uddin', 'sex' => 'male', 'mobile' => '01711-000001', 'age_years' => 40])->assertCreated()->json('data');
    $this->karim = $this->asToken($this->owner)->postJson(($this->api)('referrers'), ['name' => 'Dr. Karim', 'registration_no' => 'A-12345', 'is_active' => true])->assertCreated()->json('data');
    $this->nila = $this->asToken($this->owner)->postJson(($this->api)('referrers'), ['name' => 'Dr. Nila', 'commission_percent' => 20, 'is_active' => true])->assertCreated()->json('data');

    $this->order = fn (array $tests, array $referrer) => $this->asToken($this->reception)->postJson(($this->api)('orders'), [
        'patient_id' => $this->rahim['id'], 'test_ids' => array_column($tests, 'id'), 'referrer_id' => $referrer['id'],
    ])->assertCreated()->json('data');
    $this->finalize = function (string $billId) {
        $bill = $this->asToken($this->owner)->getJson(($this->org)("patient-billing/bills/{$billId}"))->assertOk()->json('data');
        $this->asToken($this->owner)->postJson(($this->org)("patient-billing/bills/{$billId}/finalize"), ['base_version' => $bill['version']])->assertOk();
    };
    // Every test of the order entered by the technologist and signed by the pathologist.
    $this->verify = function (array $order): array {
        $this->asToken($this->tech)->postJson(($this->api)("orders/{$order['id']}/collect"));
        $order = $this->asToken($this->tech)->getJson(($this->api)("orders/{$order['id']}"))->json('data');
        foreach ($order['tests'] as $line) {
            $value = $line['parameters'][0]['result_type'] === 'number' ? '14.2' : 'Normal';
            $this->asToken($this->tech)->putJson(($this->api)("order-tests/{$line['id']}/results"), ['values' => [$line['parameters'][0]['id'] => $value], 'base_version' => $line['version']])->assertOk();
            $this->asToken($this->pathologist)->postJson(($this->api)("order-tests/{$line['id']}/verify"))->assertOk();
        }

        return $this->asToken($this->tech)->getJson(($this->api)("orders/{$order['id']}"))->json('data');
    };
    $this->owed = fn (array $referrer) => $this->asToken($this->accounts)->getJson(($this->api)("referrers/{$referrer['id']}/commissions"))->assertOk()->json('data.owed_minor');
});

it('earns commission when the bill is final: the doctor\'s percent, else the rule, once per order', function () {
    trustedOrgRule($this->w->c1, 'diagnostics.referral_commission_percent', 10);
    $first = ($this->order)([$this->cbc, $this->xray], $this->karim);
    expect(($this->owed)($this->karim))->toBe(0);
    ($this->finalize)($first['bill_id']);
    expect(($this->owed)($this->karim))->toBe(13000);
    // The event again (a retry) earns nothing more.
    expect(app(Commissions::class)->accrueForBill($this->w->c1, $first['bill_id']))->toBe(0);

    $second = ($this->order)([$this->cbc], $this->nila);
    ($this->finalize)($second['bill_id']);
    expect(($this->owed)($this->nila))->toBe(10000)->and(($this->owed)($this->karim))->toBe(13000);

    $list = $this->asToken($this->accounts)->getJson(($this->api)('commissions'))->assertOk();
    expect($list->json('data.0.name'))->toBe('Dr. Karim')->and($list->json('meta.owed_minor'))->toBe(23000)->and($list->json('meta.percent'))->toBe(10);
    $line = Commission::query()->where('order_id', $second['id'])->first();
    expect($line->base_minor)->toBe(50000)->and($line->percent)->toBe(20)->and(fn () => $line->update(['amount_minor' => 1]))->toThrow(LogicException::class);
});

it('earns nothing without a referring doctor or with no percent', function () {
    $order = $this->asToken($this->reception)->postJson(($this->api)('orders'), ['patient_id' => $this->rahim['id'], 'test_ids' => [$this->cbc['id']]])->assertCreated()->json('data');
    $karims = ($this->order)([$this->xray], $this->karim);
    ($this->finalize)($order['bill_id']);
    expect(Commission::query()->count())->toBe(0)->and(($this->owed)($this->karim))->toBe(0);
});

it('pays a doctor up to what is owed, once per op id, and keeps the account for the period', function () {
    $order = ($this->order)([$this->cbc, $this->xray], $this->nila);
    ($this->finalize)($order['bill_id']);
    $pay = fn (array $body) => $this->asToken($this->accounts)->postJson(($this->api)("referrers/{$this->nila['id']}/payouts"), ['method' => 'cash', 'paid_on' => '2026-11-10', ...$body]);

    $pay(['amount_minor' => 26001])->assertUnprocessable()->assertJsonPath('code', 'pays_more_than_owed');
    $pay(['amount_minor' => 1000, 'paid_on' => '2026-11-11'])->assertUnprocessable()->assertJsonPath('code', 'paid_in_future');
    $pay(['amount_minor' => 1000, 'method' => 'cheque'])->assertUnprocessable()->assertJsonValidationErrors(['method']);
    $pay(['amount_minor' => 1000, 'organization_id' => $this->w->c2->id])->assertUnprocessable();
    $first = $pay(['amount_minor' => 6000, 'op_id' => 'pay-1'])->assertCreated()->assertJsonPath('data.owed_minor', 20000)->json('data.id');
    $pay(['amount_minor' => 6000, 'op_id' => 'pay-1'])->assertOk()->assertJsonPath('data.id', $first)->assertJsonPath('data.owed_minor', 20000);

    $account = $this->asToken($this->accounts)->getJson(($this->api)("referrers/{$this->nila['id']}/commissions"))->assertOk()->json('data');
    expect($account['opening_minor'])->toBe(0)->and($account['earned_minor'])->toBe(26000)->and($account['paid_minor'])->toBe(6000)
        ->and($account['closing_minor'])->toBe(20000)->and($account['commissions'][0]['order_number'])->toBe($order['number'])->and($account['payouts'])->toHaveCount(1);
    // The next month starts with what is still owed.
    $december = $this->asToken($this->accounts)->getJson(($this->api)("referrers/{$this->nila['id']}/commissions?from=2026-12-01&to=2026-12-31"))->assertOk()->json('data');
    expect($december['opening_minor'])->toBe(20000)->and($december['earned_minor'])->toBe(0)->and($december['commissions'])->toBe([]);
    $this->asToken($this->accounts)->getJson(($this->api)("referrers/{$this->nila['id']}/commissions?from=2024-01-01&to=2026-12-31"))->assertUnprocessable()->assertJsonPath('code', 'period_too_long');
});

it('takes a commission back once, with a reason, unless it is already paid out', function () {
    $order = ($this->order)([$this->cbc], $this->nila);
    ($this->finalize)($order['bill_id']);
    $due = Commission::query()->where('order_id', $order['id'])->first();
    $reverse = fn (array $body = ['reason' => 'Patient returned the test']) => $this->asToken($this->accounts)->postJson(($this->api)("commissions/{$due->id}/reverse"), $body);

    $reverse(['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $reverse()->assertOk()->assertJsonPath('data.owed_minor', 0);
    $reverse()->assertStatus(409)->assertJsonPath('code', 'already_reversed');
    $reversal = Commission::query()->where('kind', 'reversal')->first();
    expect($reversal->amount_minor)->toBe(-10000)->and($reversal->reverses_id)->toBe($due->id);
    $this->asToken($this->accounts)->postJson(($this->api)("commissions/{$reversal->id}/reverse"), ['reason' => 'Twice'])->assertStatus(409)->assertJsonPath('code', 'not_a_commission');

    $paid = ($this->order)([$this->xray], $this->nila);
    ($this->finalize)($paid['bill_id']);
    $this->asToken($this->accounts)->postJson(($this->api)("referrers/{$this->nila['id']}/payouts"), ['amount_minor' => 16000, 'method' => 'bank', 'paid_on' => '2026-11-10'])->assertCreated();
    $line = Commission::query()->where('order_id', $paid['id'])->first();
    $this->asToken($this->accounts)->postJson(($this->api)("commissions/{$line->id}/reverse"), ['reason' => 'Mistake'])->assertStatus(409)->assertJsonPath('code', 'commission_paid_out');
    $this->assertDatabaseHas('audit_logs', ['action' => 'diagnostics.commission_reversed', 'organization_id' => $this->w->c1->id]);
});

it('writes commission and payouts into the books when the company keeps them', function () {
    toggles()->enable($this->w->g1, 'accounting', 'Test setup');
    setUpBooks($this, $this->owner, $this->w->c1)->assertCreated();
    $order = ($this->order)([$this->cbc], $this->nila);
    // Cash comes in from the patient first (cash in hand never goes below nothing).
    $this->asToken($this->owner)->postJson(($this->org)('patient-billing/cash-days/open'), ['opening_float_minor' => 0])->assertCreated();
    $this->asToken($this->owner)->postJson(($this->org)('patient-billing/payments'), ['op_id' => 'cash-in', 'kind' => 'payment', 'bill_id' => $order['bill_id'], 'method' => 'cash', 'amount_minor' => 50000])->assertCreated();
    ($this->finalize)($order['bill_id']);
    $this->asToken($this->accounts)->postJson(($this->api)("referrers/{$this->nila['id']}/payouts"), ['amount_minor' => 4000, 'method' => 'cash', 'paid_on' => '2026-11-10'])->assertCreated();

    $journals = DB::table('acc_journals')->where('source_module', 'diagnostics')->pluck('id');
    $lines = DB::table('acc_journal_lines')->whereIn('journal_id', $journals)->get();
    expect($journals)->toHaveCount(2)->and((int) $lines->sum('debit_minor'))->toBe((int) $lines->sum('credit_minor'))->and((int) $lines->sum('debit_minor'))->toBe(14000);
});

it('opens a verified report from its link without signing in, until it expires', function () {
    $order = ($this->order)([$this->cbc, $this->xray], $this->karim);
    $this->asToken($this->reception)->postJson(($this->api)("orders/{$order['id']}/link"))->assertStatus(409)->assertJsonPath('code', 'not_verified');
    ($this->verify)($order);
    $made = $this->asToken($this->reception)->postJson(($this->api)("orders/{$order['id']}/link"))->assertCreated()->json('data');
    expect($made['url'])->toContain("/lab-report/{$this->w->c1->id}/")->and($made['sent_by_sms'])->toBeFalse()
        ->and(CarbonImmutable::parse($made['expires_at'])->equalTo(CarbonImmutable::now()->addHours(72)))->toBeTrue();
    $token = substr($made['url'], strrpos($made['url'], '/') + 1);
    expect(DB::table('diagnostics_report_links')->value('token_hash'))->toBe(hash('sha256', $token))->not->toBe($token);
    $this->asToken($this->reception)->getJson(($this->api)("orders/{$order['id']}"))->assertJsonPath('data.status', 'delivered');

    $public = "/api/public/diagnostics/reports/{$this->w->c1->id}/{$token}";
    app('auth')->forgetGuards();
    $report = $this->getJson($public)->assertOk()->json('data');
    expect($report['patient'])->toBe(['mrn' => $this->rahim['mrn'], 'name' => 'Rahim Uddin', 'sex' => 'male', 'age' => 40])->and($report['tests'])->toHaveCount(2)
        ->and($report['tests'][0]['parameters'][0]['value'])->toBe('14.2')->and($report)->not->toHaveKeys(['bill_id', 'samples', 'notes', 'unit_id', 'id'])
        ->and($report['referrer'])->toBe(['name' => 'Dr. Karim']);
    expect(DB::table('diagnostics_report_links')->value('opened_count'))->toBe(1);

    $this->getJson("/api/public/diagnostics/reports/{$this->w->c1->id}/".str_repeat('x', 40))->assertNotFound()->assertJsonPath('code', 'report_not_found');
    $this->getJson("/api/public/diagnostics/reports/{$this->w->c2->id}/{$token}")->assertNotFound()->assertJsonPath('code', 'report_not_found');
    $this->getJson("/api/public/diagnostics/reports/not-an-id/{$token}")->assertNotFound();
    $this->travel(73)->hours();
    $this->getJson($public)->assertForbidden()->assertJsonPath('code', 'link_expired');
});

it('takes the link hours from the rule at the order\'s branch level', function () {
    orgRule($this->w->c1, 'diagnostics.report_link_hours', 24);
    $order = ($this->verify)(($this->order)([$this->xray], $this->karim));
    $made = $this->asToken($this->reception)->postJson(($this->api)("orders/{$order['id']}/link"))->assertCreated()->json('data');
    expect(CarbonImmutable::parse($made['expires_at'])->equalTo(CarbonImmutable::now()->addHours(24)))->toBeTrue();
});

it('texts the link to the patient when asked or by rule, only with SMS on and a mobile', function () {
    $order = ($this->verify)(($this->order)([$this->xray], $this->karim));
    $this->asToken($this->reception)->postJson(($this->api)("orders/{$order['id']}/link"), ['sms' => true])->assertUnprocessable()->assertJsonPath('code', 'sms_off');

    platformRule('notifications.sms_enabled', true);
    $this->asToken($this->reception)->postJson(($this->api)("orders/{$order['id']}/link"), ['sms' => true])->assertCreated()->assertJsonPath('data.sent_by_sms', true);
    $sent = NotificationDelivery::query()->where('notification_key', 'diagnostics.report_link')->where('channel', 'sms')->get();
    expect($sent)->toHaveCount(1)->and($sent[0]->organization_id)->toBe($this->w->c1->id);

    // By rule: as soon as every test is verified, once.
    orgRule($this->w->c1, 'diagnostics.send_report_sms', true);
    ($this->verify)(($this->order)([$this->xray], $this->karim));
    expect(NotificationDelivery::query()->where('notification_key', 'diagnostics.report_link')->count())->toBe(2);

    $silent = $this->asToken($this->owner)->postJson(($this->org)('patients/patients'), ['name' => 'Silent', 'sex' => 'female', 'mobile' => null, 'age_years' => 30])->assertCreated()->json('data');
    $quiet = $this->asToken($this->reception)->postJson(($this->api)('orders'), ['patient_id' => $silent['id'], 'test_ids' => [$this->xray['id']]])->assertCreated()->json('data');
    $quiet = ($this->verify)($quiet);
    expect(NotificationDelivery::query()->where('notification_key', 'diagnostics.report_link')->count())->toBe(2);
    $this->asToken($this->reception)->postJson(($this->api)("orders/{$quiet['id']}/link"), ['sms' => true])->assertUnprocessable()->assertJsonPath('code', 'no_mobile');
});

it('needs the commission and deliver permissions and the modules on', function () {
    $this->asToken($this->reception)->getJson(($this->api)('commissions'))->assertForbidden();
    $this->asToken($this->reception)->postJson(($this->api)("referrers/{$this->nila['id']}/payouts"), ['amount_minor' => 100, 'method' => 'cash', 'paid_on' => '2026-11-10'])->assertForbidden();
    $order = ($this->verify)(($this->order)([$this->xray], $this->karim));
    $this->asToken($this->accounts)->postJson(($this->api)("orders/{$order['id']}/link"))->assertForbidden();
    $made = $this->asToken($this->reception)->postJson(($this->api)("orders/{$order['id']}/link"))->assertCreated()->json('data');
    $token = substr($made['url'], strrpos($made['url'], '/') + 1);

    toggles()->disable($this->w->c1, 'diagnostics', 'Test');
    $this->asToken($this->accounts)->getJson(($this->api)('commissions'))->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson("/api/public/diagnostics/reports/{$this->w->c1->id}/{$token}")->assertNotFound();
});

it('never shows or pays another company\'s or another partner\'s doctors', function () {
    $order = ($this->order)([$this->cbc], $this->nila);
    ($this->finalize)($order['bill_id']);
    $due = Commission::query()->first();
    $sister = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($sister)->getJson(($this->api)("referrers/{$this->nila['id']}/commissions", $this->w->c2))->assertNotFound();
    $this->asToken($sister)->postJson(($this->api)("referrers/{$this->nila['id']}/payouts", $this->w->c2), ['amount_minor' => 100, 'method' => 'cash', 'paid_on' => '2026-11-10'])->assertNotFound();
    $this->asToken($sister)->postJson(($this->api)("commissions/{$due->id}/reverse", $this->w->c2), ['reason' => 'Not ours'])->assertNotFound();
    $this->asToken($sister)->getJson(($this->api)("referrers/{$this->nila['id']}/commissions"))->assertNotFound();
    expect($this->asToken($sister)->getJson(($this->api)('commissions', $this->w->c2))->assertOk()->json('data'))->toBe([]);

    setPlan($this->w->g3, 'business');
    foreach (['patients', 'patient_billing', 'diagnostics'] as $module) {
        toggles()->enable($this->w->g3, $module, 'Test setup');
    }
    $elsewhere = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($elsewhere)->postJson(($this->api)("referrers/{$this->nila['id']}/payouts", $this->w->c4), ['amount_minor' => 100, 'method' => 'cash', 'paid_on' => '2026-11-10'])->assertNotFound();
    $this->asToken($elsewhere)->postJson(($this->api)("orders/{$order['id']}/link", $this->w->c4))->assertNotFound();
});
