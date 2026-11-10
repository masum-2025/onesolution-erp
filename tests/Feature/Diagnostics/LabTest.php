<?php

use App\Models\User;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Diagnostics\Contracts\TestOrders;
use Modules\Diagnostics\Events\ReportReady;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Models\TestResult;
use Modules\Diagnostics\Services\ResultFlags;

/*
 * H-2a Diagnostics: tests with values and normal ranges, an order billed on
 * the patient's bill, barcoded samples (rejected ones re-taken), results
 * flagged by sex and age, verification by a second person, amendments that
 * keep the old values, reports only when verified, the portal, the contract
 * for other modules, rules by level, permissions, modules off, isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
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
    $this->phlebotomist = $role(['diagnostics.view', 'diagnostics.collect'], 'Phlebotomist');
    $this->tech = $role(['diagnostics.view', 'diagnostics.result', 'diagnostics.verify'], 'Technologist');
    $this->pathologist = $role(['diagnostics.view', 'diagnostics.result', 'diagnostics.verify'], 'Pathologist');
    $this->viewer = $role(['diagnostics.view'], 'Viewer');
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/diagnostics/{$path}";
    $org = fn ($unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id;

    $this->patient = fn (string $name, string $sex, array $age, $token = null, $unit = null) => $this->asToken($token ?? $this->owner)
        ->postJson($org($unit).'/patients/patients', ['name' => $name, 'sex' => $sex, 'mobile' => null, ...$age])->assertCreated()->json('data');
    $this->setUpLab = function ($token = null, $unit = null) use ($org) {
        foreach ([['CBC', 50000], ['XRAY', 80000]] as [$code, $price]) {
            $this->asToken($token ?? $this->owner)->postJson($org($unit).'/patient-billing/services', ['code' => $code, 'name' => ['en' => $code], 'category' => 'test', 'price_minor' => $price, 'is_active' => true])->assertCreated();
        }
        $cbc = $this->asToken($token ?? $this->owner)->postJson(($this->api)('tests', $unit), ['code' => 'CBC', 'name' => ['en' => 'Complete blood count', 'bn' => 'সিবিসি'], 'department' => 'haematology', 'sample_type' => 'blood', 'service_code' => 'CBC', 'is_active' => true])->assertCreated()->json('data');
        $xray = $this->asToken($token ?? $this->owner)->postJson(($this->api)('tests', $unit), ['code' => 'XRAY', 'name' => ['en' => 'Chest X-ray'], 'department' => 'imaging', 'sample_type' => 'none', 'service_code' => 'XRAY', 'turnaround_hours' => 4, 'is_active' => true])->assertCreated()->json('data');
        $this->asToken($token ?? $this->owner)->putJson(($this->api)("tests/{$cbc['id']}/parameters", $unit), ['parameters' => [
            ['name' => ['en' => 'Haemoglobin', 'bn' => 'হিমোগ্লোবিন'], 'unit' => 'g/dL', 'result_type' => 'number', 'decimals' => 1, 'ranges' => [
                ['sex' => 'male', 'age_from_days' => 6570, 'low' => '13', 'high' => '17', 'critical_low' => '7'],
                ['sex' => 'female', 'age_from_days' => 6570, 'low' => '12', 'high' => '15', 'critical_low' => '7'],
                ['sex' => 'any', 'age_from_days' => 0, 'age_to_days' => 30, 'low' => '14', 'high' => '22'],
            ]],
            ['name' => ['en' => 'White cells'], 'unit' => '/cumm', 'result_type' => 'number', 'decimals' => 0, 'ranges' => [['sex' => 'any', 'low' => '4000', 'high' => '11000']]],
        ]])->assertOk();
        $this->asToken($token ?? $this->owner)->putJson(($this->api)("tests/{$xray['id']}/parameters", $unit), ['parameters' => [
            ['name' => ['en' => 'Findings'], 'result_type' => 'text', 'ranges' => [['sex' => 'any', 'normal_text' => 'Normal']]],
        ]])->assertOk();

        return [$cbc, $xray];
    };
    [$this->cbc, $this->xray] = ($this->setUpLab)();
    $this->rahim = ($this->patient)('Rahim Uddin', 'male', ['age_years' => 40]);
    $this->referrer = $this->asToken($this->owner)->postJson(($this->api)('referrers'), ['name' => 'Dr. Karim', 'registration_no' => 'A-12345', 'is_active' => true])->assertCreated()->json('data');
    $this->order = fn (array $tests, ?array $patient = null, $token = null) => $this->asToken($token ?? $this->reception)->postJson(($this->api)('orders'), [
        'patient_id' => ($patient ?? $this->rahim)['id'], 'test_ids' => array_column($tests, 'id'), 'referrer_id' => $this->referrer['id'],
    ])->assertCreated()->json('data');
    $this->results = fn (array $line, array $values, $token) => $this->asToken($token)->putJson(($this->api)("order-tests/{$line['id']}/results"), ['values' => $values, 'base_version' => $line['version']]);
    $this->testOf = fn (array $order, string $code) => collect($order['tests'])->firstWhere('code', $code);
    $this->param = fn (array $line, string $name) => collect($line['parameters'])->firstWhere('name', $name);
});

it('takes an order from billing to a verified, delivered report', function () {
    Event::fake([ReportReady::class]);
    $order = ($this->order)([$this->cbc, $this->xray]);
    expect($order['number'])->toBe('D000001')->and($order['status'])->toBe('ordered')->and($order['referrer']['name'])->toBe('Dr. Karim')
        ->and(($this->testOf)($order, 'XRAY')['status'])->toBe('collected')->and($order['bill_due_minor'])->toBe(130000);
    $bill = $this->asToken($this->owner)->getJson("/api/organizations/{$this->w->c1->id}/patient-billing/bills/{$order['bill_id']}")->assertOk()->json('data');
    expect(collect($bill['lines'])->pluck('source_module')->unique()->all())->toBe(['diagnostics'])->and($bill['total_minor'])->toBe(130000);

    // No results before the sample.
    ($this->results)(($this->testOf)($order, 'CBC'), [], $this->tech)->assertStatus(409)->assertJsonPath('code', 'not_collected');
    $order = $this->asToken($this->phlebotomist)->postJson(($this->api)("orders/{$order['id']}/collect"))->assertOk()->json('data');
    expect($order['samples'])->toHaveCount(1)->and($order['samples'][0]['barcode'])->toBe('S0000001')->and($order['status'])->toBe('collected')->and($order['due_at'])->not->toBeNull();
    $this->asToken($this->phlebotomist)->postJson(($this->api)("orders/{$order['id']}/collect"))->assertStatus(409)->assertJsonPath('code', 'nothing_to_collect');
    $this->asToken($this->tech)->getJson(($this->api)('samples/s0000001'))->assertOk()->assertJsonPath('data.order_id', $order['id']);
    $this->asToken($this->tech)->getJson(($this->api)('samples/S9999999'))->assertNotFound()->assertJsonPath('code', 'sample_not_found');

    // Results, flagged for a 40-year-old man.
    $cbc = ($this->testOf)($order, 'CBC');
    $hb = ($this->param)($cbc, 'Haemoglobin');
    $wbc = ($this->param)($cbc, 'White cells');
    expect($hb['range']['low'])->toBe('13')->and($hb['range']['high'])->toBe('17');
    ($this->results)($cbc, [$hb['id'] => 'twelve'], $this->tech)->assertUnprocessable()->assertJsonValidationErrors(["values.{$hb['id']}"]);
    ($this->results)($cbc, ['01ZZZZZZZZZZZZZZZZZZZZZZZZ' => '1'], $this->tech)->assertUnprocessable()->assertJsonValidationErrors(['values']);
    $order = ($this->results)($cbc, [$hb['id'] => '11.25', $wbc['id'] => '১২০০০'], $this->tech)->assertOk()->json('data');
    $cbc = ($this->testOf)($order, 'CBC');
    expect($cbc['status'])->toBe('resulted')->and(($this->param)($cbc, 'Haemoglobin')['value'])->toBe('11.3')->and(($this->param)($cbc, 'Haemoglobin')['flag'])->toBe('low')
        ->and(($this->param)($cbc, 'White cells')['value'])->toBe('12000')->and(($this->param)($cbc, 'White cells')['flag'])->toBe('high');

    // A second person signs.
    $this->asToken($this->tech)->postJson(($this->api)("order-tests/{$cbc['id']}/verify"))->assertForbidden()->assertJsonPath('code', 'own_results');
    $this->asToken($this->pathologist)->postJson(($this->api)("order-tests/{$cbc['id']}/verify"), ['note' => 'Checked'])->assertOk();
    $this->asToken($this->reception)->postJson(($this->api)("orders/{$order['id']}/deliver"), ['channel' => 'print'])->assertStatus(409)->assertJsonPath('code', 'not_verified');
    $xray = ($this->testOf)($order, 'XRAY');
    $order = ($this->results)($xray, [($this->param)($xray, 'Findings')['id'] => 'Fracture of 5th rib'], $this->pathologist)->assertOk()->json('data');
    expect(($this->param)(($this->testOf)($order, 'XRAY'), 'Findings')['flag'])->toBe('abnormal');
    $order = $this->asToken($this->tech)->postJson(($this->api)('order-tests/'.($this->testOf)($order, 'XRAY')['id'].'/verify'))->assertOk()->json('data');
    expect($order['status'])->toBe('verified');
    Event::assertDispatched(ReportReady::class, fn ($event) => $event->orderId === $order['id']);

    $report = $this->asToken($this->viewer)->getJson(($this->api)("orders/{$order['id']}/report"))->assertOk()->json('data');
    expect(($this->testOf)($report, 'CBC')['verified_by_name'])->not->toBeNull()->and($report['samples'])->toBe([])->and($report)->toHaveKey('organization');
    $this->asToken($this->reception)->postJson(($this->api)("orders/{$order['id']}/deliver"), ['channel' => 'print'])->assertOk()->assertJsonPath('data.status', 'delivered');
    $history = $this->asToken($this->owner)->getJson("/api/organizations/{$this->w->c1->id}/patients/patients/{$this->rahim['id']}/timeline")->json('data.entries');
    expect(collect($history)->pluck('title'))->toContain('Lab report D000001');
});

it('re-takes a rejected sample with a new barcode', function () {
    $order = ($this->order)([$this->cbc]);
    $order = $this->asToken($this->phlebotomist)->postJson(($this->api)("orders/{$order['id']}/collect"))->assertOk()->json('data');
    $this->asToken($this->viewer)->postJson(($this->api)("samples/{$order['samples'][0]['id']}/reject"), ['reason' => 'Clotted'])->assertForbidden();
    $order = $this->asToken($this->phlebotomist)->postJson(($this->api)("samples/{$order['samples'][0]['id']}/reject"), ['reason' => 'Clotted'])->assertOk()->json('data');
    expect($order['status'])->toBe('ordered')->and($order['samples'][0]['rejected_reason'])->toBe('Clotted');
    $order = $this->asToken($this->phlebotomist)->postJson(($this->api)("orders/{$order['id']}/collect"))->assertOk()->json('data');
    expect(collect($order['samples'])->pluck('barcode')->all())->toBe(['S0000001', 'S0000002'])->and(($this->testOf)($order, 'CBC')['barcode'])->toBe('S0000002');
});

it('keeps old values when verified results are changed, and the report shows the last signed ones', function () {
    $order = ($this->order)([$this->cbc]);
    $order = $this->asToken($this->phlebotomist)->postJson(($this->api)("orders/{$order['id']}/collect"))->json('data');
    $cbc = ($this->testOf)($order, 'CBC');
    $hb = ($this->param)($cbc, 'Haemoglobin')['id'];
    $cbc = ($this->testOf)(($this->results)($cbc, [$hb => '14'], $this->tech)->json('data'), 'CBC');
    $this->asToken($this->pathologist)->postJson(($this->api)("order-tests/{$cbc['id']}/verify"))->assertOk();
    $cbc = ($this->testOf)($this->asToken($this->tech)->getJson(($this->api)("orders/{$order['id']}"))->json('data'), 'CBC');
    // Version guard, then a new amendment.
    $this->asToken($this->tech)->putJson(($this->api)("order-tests/{$cbc['id']}/results"), ['values' => [$hb => '6.5'], 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $order = ($this->results)($cbc, [$hb => '6.5'], $this->tech)->assertOk()->json('data');
    $now = ($this->testOf)($order, 'CBC');
    expect($now['amendment'])->toBe(2)->and($now['status'])->toBe('resulted')->and(($this->param)($now, 'Haemoglobin')['flag'])->toBe('critical_low')->and($order['status'])->toBe('resulted');

    $report = $this->asToken($this->viewer)->getJson(($this->api)("orders/{$order['id']}/report"))->json('data');
    expect(($this->testOf)($report, 'CBC')['amendment'])->toBe(1)->and(($this->param)(($this->testOf)($report, 'CBC'), 'Haemoglobin')['value'])->toBe('14.0');
    expect(TestResult::query()->withoutGlobalScopes()->count())->toBe(4)
        ->and(fn () => TestResult::query()->withoutGlobalScopes()->first()->forceFill(['value' => '1'])->save())->toThrow(LogicException::class);

    // Sent back: the results stay on record, the bench enters them again.
    $order = $this->asToken($this->pathologist)->postJson(($this->api)("order-tests/{$now['id']}/send-back"), ['reason' => 'Repeat on a fresh run'])->assertOk()->json('data');
    expect(($this->testOf)($order, 'CBC')['status'])->toBe('collected')->and(($this->testOf)($order, 'CBC')['send_back_note'])->toBe('Repeat on a fresh run');
});

it('picks the normal range by sex and age, and flags numbers without floats', function () {
    $karima = ($this->patient)('Karima Begum', 'female', ['age_years' => 30]);
    $baby = ($this->patient)('Baby of Karima', 'male', ['date_of_birth' => '2026-10-25']);
    $range = fn (array $patient) => ($this->param)(($this->testOf)(($this->order)([$this->cbc], $patient), 'CBC'), 'Haemoglobin')['range'];
    expect($range($karima)['high'])->toBe('15')->and($range($baby)['low'])->toBe('14')->and($range($baby)['high'])->toBe('22');

    $ranges = [['sex' => 'any', 'age_from_days' => 0, 'age_to_days' => null, 'low' => '3.5', 'high' => '5.1', 'critical_low' => '2.5', 'critical_high' => '6.5', 'normal_text' => null]];
    $fit = ResultFlags::rangeFor($ranges, 'male', 9000);
    expect(ResultFlags::flag('number', '3.4999', $fit))->toBe('low')->and(ResultFlags::flag('number', '3.5', $fit))->toBeNull()
        ->and(ResultFlags::flag('number', '6.5', $fit))->toBe('critical_high')->and(ResultFlags::flag('number', '-1', $fit))->toBe('critical_low')
        ->and(ResultFlags::normalised('০.৫৫', 1))->toBe('0.6')->and(ResultFlags::normalised('-0.04', 1))->toBe('0.0')->and(ResultFlags::scaled('1e5'))->toBeNull();
});

it('lets a small lab skip the second signature when the rule allows', function () {
    trustedOrgRule($this->w->c1, 'diagnostics.require_verification', false);
    $order = ($this->order)([$this->xray]);
    $xray = ($this->testOf)($order, 'XRAY');
    $order = ($this->results)($xray, [($this->param)($xray, 'Findings')['id'] => 'Normal'], $this->tech)->assertOk()->json('data');
    expect($order['status'])->toBe('verified')->and(($this->testOf)($order, 'XRAY')['verified_by_name'])->not->toBeNull()->and(($this->param)(($this->testOf)($order, 'XRAY'), 'Findings')['flag'])->toBeNull();
});

it('orders tests for other modules once per source record through TestOrders', function () {
    $actor = User::query()->findOrFail($this->ownerUser->id);
    $first = app(TestOrders::class)->place($this->w->c1, $this->w->c1, $this->rahim['id'], ['CBC', 'XRAY'], 'outpatient', 'VISIT-1', $actor);
    $again = app(TestOrders::class)->place($this->w->c1, $this->w->c1, $this->rahim['id'], ['CBC', 'XRAY'], 'outpatient', 'VISIT-1', $actor);
    expect($again)->toBe($first)->and(collect(app(TestOrders::class)->catalogue($this->w->c1))->pluck('code')->all())->toBe(['CBC', 'XRAY']);
    expect(fn () => app(TestOrders::class)->place($this->w->c1, $this->w->c1, $this->rahim['id'], ['NOPE'], 'outpatient', 'VISIT-2', $actor))->toThrow(DiagnosticsException::class);
    expect(DB::table('patient_billing_bill_lines')->where('source_module', 'diagnostics')->count())->toBe(2);
});

it('shows portal patients only their own verified reports', function () {
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    trustedOrgRule($this->w->c1, 'diagnostics.require_verification', false);
    $done = ($this->order)([$this->xray]);
    ($this->results)(($this->testOf)($done, 'XRAY'), [($this->param)(($this->testOf)($done, 'XRAY'), 'Findings')['id'] => 'Normal'], $this->tech)->assertOk();
    ($this->order)([$this->cbc]);
    $karima = ($this->patient)('Karima Begum', 'female', ['age_years' => 30]);
    ($this->order)([$this->xray], $karima);

    $person = User::factory()->create(['email_verified_at' => now()]);
    app(AddMember::class)->handle($this->w->c1, $person, MembershipType::Portal, AccessScope::Own);
    $membership = OrganizationMembership::query()->where('user_id', $person->id)->where('organization_id', $this->w->c1->id)->sole();
    (new PortalLink)->forceFill([
        'organization_id' => $this->w->c1->id, 'membership_id' => $membership->id, 'user_id' => $person->id,
        'subject_type' => 'patients.patient', 'subject_id' => $this->rahim['id'], 'relation' => 'self', 'status' => PortalLink::ACTIVE, 'linked_via' => 'invitation',
    ])->save();
    $portal = orgToken($person, $this->w->c1);
    $this->withHeader('Origin', config('app.url'));

    $reports = $this->asToken($portal)->getJson('/api/portal/diagnostics/reports')->assertOk()->json('data.reports');
    expect(collect($reports)->pluck('id')->all())->toBe([$done['id']])->and($reports[0])->not->toHaveKeys(['samples', 'bill_due_minor', 'notes']);
    $this->asToken($portal)->getJson(($this->api)("orders/{$done['id']}"))->assertForbidden();
});

it('checks the billing code of a test, numbers by level, permissions and the modules being on', function () {
    $this->asToken($this->owner)->postJson(($this->api)('tests'), ['code' => 'LFT', 'name' => ['en' => 'Liver'], 'department' => 'biochemistry', 'sample_type' => 'serum', 'service_code' => 'NOPE'])
        ->assertUnprocessable()->assertJsonValidationErrors(['service_code']);
    orgRule($this->w->g1, 'diagnostics.number_prefixes', ['order' => 'LAB', 'sample' => 'T']);
    expect(($this->order)([$this->cbc])['number'])->toBe('LAB000001');
    $this->asToken($this->owner)->deleteJson(($this->api)("tests/{$this->cbc['id']}?base_version=1"))->assertStatus(409)->assertJsonPath('code', 'in_use');

    $this->asToken($this->viewer)->postJson(($this->api)('orders'), ['patient_id' => $this->rahim['id'], 'test_ids' => [$this->cbc['id']]])->assertForbidden();
    $this->asToken($this->reception)->putJson(($this->api)("tests/{$this->cbc['id']}/parameters"), ['parameters' => []])->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.order', false)->assertJsonPath('data.require_verification', true)
        ->assertJsonPath('data.options.tests.0.price_minor', 50000);
    $this->asToken($this->reception)->postJson(($this->api)('orders'), ['patient_id' => $this->rahim['id'], 'test_ids' => [$this->cbc['id'], $this->cbc['id']]])->assertUnprocessable();

    toggles()->disable($this->w->c1, 'diagnostics', 'Test');
    $this->asToken($this->owner)->getJson(($this->api)('orders'))->assertForbidden();
    toggles()->enable($this->w->c1, 'diagnostics', 'Test');
    toggles()->disable($this->w->c1, 'patient_billing', 'Test', confirm: true);
    $this->asToken($this->owner)->getJson(($this->api)('orders'))->assertForbidden();
});

it('never shows or touches another company\'s or another partner\'s lab work', function () {
    $order = ($this->order)([$this->cbc]);
    $sister = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($sister)->getJson(($this->api)("orders/{$order['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($sister)->getJson(($this->api)("orders/{$order['id']}"))->assertNotFound();
    $this->asToken($sister)->postJson(($this->api)("orders/{$order['id']}/collect", $this->w->c2))->assertNotFound();
    $this->asToken($sister)->postJson(($this->api)('orders', $this->w->c2), ['patient_id' => $this->rahim['id'], 'test_ids' => [$this->cbc['id']]])->assertNotFound()->assertJsonPath('code', 'patient_not_found');

    setPlan($this->w->g3, 'business');
    foreach (['patients', 'patient_billing', 'diagnostics'] as $module) {
        toggles()->enable($this->w->g3, $module, 'Test setup');
    }
    $elsewhere = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($elsewhere)->putJson(($this->api)('order-tests/'.$order['tests'][0]['id'].'/results', $this->w->c4), ['values' => [], 'base_version' => 1])->assertNotFound();
    $this->asToken($elsewhere)->getJson(($this->api)('samples/S0000001', $this->w->c4))->assertNotFound();
    // Its own lab counts from one.
    ($this->setUpLab)($elsewhere, $this->w->c4);
    $theirs = ($this->patient)('Someone', 'male', ['age_years' => 50], $elsewhere, $this->w->c4);
    $tests = $this->asToken($elsewhere)->getJson(($this->api)('setup', $this->w->c4))->json('data.options.tests');
    $this->asToken($elsewhere)->postJson(($this->api)('orders', $this->w->c4), ['patient_id' => $theirs['id'], 'test_ids' => [$tests[0]['id']]])->assertCreated()->assertJsonPath('data.number', 'D000001');
});
