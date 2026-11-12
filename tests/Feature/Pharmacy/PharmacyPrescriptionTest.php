<?php

use Carbon\CarbonImmutable;

/*
 * PH-2a Selling from a doctor's prescription at the pharmacy counter: found by
 * its printed code or the patient, only finished and in force, never the
 * diagnosis, the patient's "who looked" log, earlier sales as a warning, the
 * doctor and patient taken from the prescription, the rule's days, branch
 * sharing, who may look, Outpatient off, and other companies and partners.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = hrmWorld($this);
    $this->modules = ['inventory', 'pos', 'accounting', 'pharmacy', 'patients', 'patient_billing', 'outpatient'];
    setPlan($this->w->g1, 'business');
    foreach ($this->modules as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->owner = orgToken(createMember($this->w->c1), $this->w->c1);
    $role = fn (array $permissions, string $name) => orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, $permissions, $name)), $this->w->c1);
    $this->pharmacist = $role(['inventory.view', 'pos.view', 'pos.sell', 'pharmacy.view', 'pharmacy.sell'], 'Pharmacist');
    $this->cashier = $role(['inventory.view', 'pos.view', 'pos.sell'], 'Cashier');
    $doctorUser = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['outpatient.view', 'outpatient.consult', 'patients.view', 'patients.treat'], 'Doctor'));
    $this->doctor = orgToken($doctorUser, $this->w->c1);
    $this->registrar = $role(['pharmacy.view', 'pharmacy.controlled'], 'Register keeper');
    $this->org = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/{$path}";
    // The store keeper sets up books, counters and medicines (an owner does not hold both sides of a separated pair).
    $this->keeperAt = fn ($company) => orgToken(staffWithRoles($company, makeRole($company, ['inventory.view', 'inventory.manage', 'pos.view', 'pos.manage', 'accounting.view', 'accounting.manage', 'pharmacy.view', 'pharmacy.manage'], 'Store keeper')), $company);
    $this->keeper = ($this->keeperAt)($this->w->c1);
    setUpBooks($this, $this->keeper, $this->w->c1)->assertCreated();

    // A counter at a unit, its shift opened by the seller.
    $this->counterAt = function ($unit, string $code, string $keeper, string $seller, $company) {
        $shop = $this->asToken($keeper)->postJson(($this->org)('inventory/warehouses', $company), ['code' => "W{$code}", 'name' => ['en' => "Shop {$code}"], 'unit_id' => $unit->id])->assertCreated()->json('data');
        $till = $this->asToken($keeper)->postJson(($this->org)('pos/registers', $company), ['code' => $code, 'name' => ['en' => "Counter {$code}"], 'unit_id' => $unit->id,
            'warehouse_id' => $shop['id'], 'payment_methods' => ['cash']])->assertCreated()->json('data');
        $this->asToken($seller)->postJson(($this->org)("pos/registers/{$till['id']}/open", $company), ['opening_float_minor' => 0])->assertCreated();

        return [$shop, $till];
    };
    [$this->shop, $this->till] = ($this->counterAt)($this->w->c1, 'T1', $this->keeper, $this->pharmacist, $this->w->c1);

    $generic = fn (string $name, bool $controlled = false) => $this->asToken($this->keeper)->postJson(($this->org)('pharmacy/generics'), ['name' => ['en' => $name], 'is_controlled' => $controlled, 'in_use' => true])->assertCreated()->json('data');
    $medicine = fn (string $name, string $sku, array $generic, int $mrp) => $this->asToken($this->keeper)->postJson(($this->org)('pharmacy/medicines'), [
        'brand_name' => ['en' => $name], 'sku' => $sku, 'generic_id' => $generic['id'], 'mrp_minor' => $mrp, 'form' => 'tablet', 'strength' => '500 mg', 'pieces_per_strip' => 10, 'strips_per_box' => 10,
    ])->assertCreated()->json('data');
    $this->napa = $medicine('Napa', 'NAPA500', $generic('Paracetamol'), 120);
    $this->sedil = $medicine('Sedil', 'SEDIL5', $generic('Diazepam', true), 500);
    $this->asToken($this->keeper)->postJson(($this->org)('pharmacy/purchases'), ['op_id' => (string) str()->ulid(), 'warehouse_id' => $this->shop['id'], 'supplier' => 'Beximco', 'purchased_on' => '2026-11-01', 'lines' => [
        ['medicine_id' => $this->napa['id'], 'pack' => 'box', 'quantity' => 2, 'pack_cost_minor' => 9000, 'batch_number' => 'N1', 'expires_on' => '2027-06-30'],
        ['medicine_id' => $this->sedil['id'], 'pack' => 'strip', 'quantity' => 2, 'pack_cost_minor' => 4000, 'batch_number' => 'S1', 'expires_on' => '2027-06-30'],
    ]])->assertCreated();

    // A doctor's session, a patient and a serial today.
    $this->asToken($this->owner)->postJson(($this->org)('patient-billing/services'), ['code' => 'FEE', 'name' => ['en' => 'Fee'], 'category' => 'consultation', 'price_minor' => 50000, 'is_active' => true])->assertCreated();
    $doctor = $this->asToken($this->owner)->postJson(($this->org)('outpatient/doctors'), ['name' => ['en' => 'Dr. Ayesha Rahman'], 'registration_no' => 'A-1001', 'fee_service_code' => 'FEE', 'is_active' => true])->assertCreated()->json('data');
    $chamber = $this->asToken($this->owner)->postJson(($this->org)('outpatient/chambers'), ['name' => 'Chamber 1', 'room' => '101', 'is_active' => true])->assertCreated()->json('data');
    $version = $this->asToken($this->owner)->getJson(($this->org)("outpatient/doctors/{$doctor['id']}/schedule"))->json('data.doctor.version');
    $this->session = $this->asToken($this->owner)->putJson(($this->org)("outpatient/doctors/{$doctor['id']}/schedule"), [
        'user_id' => $doctorUser->id, 'base_version' => $version, 'sessions' => [['chamber_id' => $chamber['id'], 'weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '20:00']],
    ])->assertOk()->json('data.sessions.0');
    $this->rahim = $this->asToken($this->owner)->postJson(($this->org)('patients/patients'), ['name' => 'Rahim Uddin', 'sex' => 'male', 'mobile' => null, 'age_years' => 40])->assertCreated()->json('data');
    $this->serial = $this->asToken($this->owner)->postJson(($this->org)('outpatient/serials'), ['schedule_id' => $this->session['id'], 'date' => '2026-11-02', 'patient_id' => $this->rahim['id']])->assertCreated()->json('data');

    $this->write = function (array $medicines = []) {
        $saved = $this->asToken($this->doctor)->putJson(($this->org)("outpatient/serials/{$this->serial['id']}/consultation"), [
            'complaints' => 'Fever for 3 days', 'diagnosis' => 'Viral fever', 'advice' => 'Rest well', 'medicines' => $medicines ?: [
                ['medicine_id' => $this->napa['id'], 'medicine_text' => 'Napa 500 mg', 'dose' => '1+0+1', 'duration' => '7 days', 'timing' => 'after_meal'],
                ['medicine_id' => $this->sedil['id'], 'medicine_text' => 'Sedil 5 mg', 'dose' => '0+0+1', 'duration' => '5 days', 'timing' => 'bedtime'],
                ['medicine_text' => 'ORS', 'dose' => 'after each loose stool', 'timing' => 'as_needed'],
            ],
        ])->assertSuccessful()->json('data');

        return $saved;
    };
    $this->finish = fn (array $saved) => $this->asToken($this->doctor)->postJson(($this->org)("outpatient/serials/{$this->serial['id']}/consultation/finish"), ['base_version' => $saved['version']])->assertOk()->json('data');
    $this->search = fn (string $term, ?string $token = null, ?array $till = null) => $this->asToken($token ?? $this->pharmacist)->getJson(($this->org)('pharmacy/counter/'.($till ?? $this->till)['id'].'/prescriptions?search='.urlencode($term)));
    $this->open = fn (string $id, ?string $token = null, ?array $till = null) => $this->asToken($token ?? $this->pharmacist)->getJson(($this->org)('pharmacy/counter/'.($till ?? $this->till)['id']."/prescriptions/{$id}"));
    $this->sell = fn (array $lines, array $prescription, ?string $token = null) => $this->asToken($token ?? $this->pharmacist)->postJson(($this->org)('pharmacy/sales'), [
        'op_id' => (string) str()->ulid(), 'register_id' => $this->till['id'], 'lines' => $lines, 'payments' => [['method' => 'cash', 'amount_minor' => 1000000]], 'prescription' => $prescription,
    ]);
});

it('finds a finished prescription by its code or the patient, never with the diagnosis, and sells from it', function () {
    $draft = ($this->write)();
    // A draft is not a prescription yet.
    expect(($this->search)('Rahim')->assertOk()->json('data'))->toBe([]);
    ($this->open)($draft['id'])->assertNotFound()->assertJsonPath('code', 'prescription_not_found');
    $this->asToken($this->pharmacist)->getJson(($this->org)("pharmacy/counter/{$this->till['id']}"))->assertOk()->assertJsonPath('meta.prescriptions_on', true);

    $done = ($this->finish)($draft);
    $rows = ($this->search)('Rahim')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['id' => $done['id'], 'date' => '2026-11-02', 'patient_name' => 'Rahim Uddin', 'doctor_name' => 'Dr. Ayesha Rahman']);
    expect(($this->search)(strtoupper($done['id']))->json('data.0.id'))->toBe($done['id'])
        ->and(($this->search)($this->rahim['mrn'])->json('data'))->toHaveCount(1)
        ->and(($this->search)('Nobody')->json('data'))->toBe([]);
    ($this->search)('x')->assertUnprocessable()->assertJsonValidationErrors('search');

    $opened = ($this->open)($done['id'])->assertOk();
    $found = $opened->json('data');
    expect($found)->toMatchArray(['id' => $done['id'], 'amended' => false, 'patient_name' => 'Rahim Uddin', 'doctor_registration_no' => 'A-1001', 'earlier_sales' => []])
        ->and($found['medicines'])->toHaveCount(3)
        ->and($found['medicines'][0])->toMatchArray(['medicine_id' => $this->napa['id'], 'dose' => '1+0+1', 'duration' => '7 days'])
        ->and($found['medicines'][2])->toMatchArray(['medicine_id' => null, 'medicine_text' => 'ORS'])
        ->and($found)->not->toHaveKeys(['diagnosis', 'complaints', 'advice', 'findings']);
    expect($opened->getContent())->not->toContain('Viral fever')->not->toContain('Fever for 3 days')->not->toContain('Rest well');

    // Opening it is in the patient's "who looked" log.
    $looks = $this->asToken($this->owner)->getJson(($this->org)("patients/patients/{$this->rahim['id']}/views"))->assertOk()->json('data');
    expect(collect($looks)->pluck('purpose')->all())->toContain('prescription');

    // Sold from it: the controlled medicine needs nothing typed; the doctor and patient come from the prescription.
    $sold = ($this->sell)([['medicine_id' => $this->sedil['id'], 'pack' => 'piece', 'quantity' => 5]], ['consultation_id' => $done['id'], 'patient_name' => 'Someone else', 'prescriber_bmdc' => 'Z-1'])->assertCreated()->json('data');
    expect($sold['from_prescription'])->toBeTrue();
    $sale = collect($this->asToken($this->registrar)->getJson(($this->org)('pharmacy/sales'))->assertOk()->json('data'))->firstWhere('id', $sold['id']);
    expect($sale['prescription'])->toMatchArray(['patient_name' => 'Rahim Uddin', 'prescriber_name' => 'Dr. Ayesha Rahman', 'prescriber_bmdc' => 'A-1001']);
    $register = $this->asToken($this->registrar)->getJson(($this->org)('pharmacy/controlled-register'))->assertOk()->json('data');
    expect(collect($register)->firstWhere('direction', 'out')['patient_name'] ?? null)->toBe('Rahim Uddin');

    // Selling again from it is allowed, with the earlier sale shown.
    expect(($this->open)($done['id'])->json('data.earlier_sales'))->toHaveCount(1)->and(($this->open)($done['id'])->json('data.earlier_sales.0.number'))->toBe($sold['number']);
    ($this->sell)([['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 1]], ['consultation_id' => $done['id']])->assertCreated();

    // A made-up or draft prescription cannot be sold from.
    ($this->sell)([['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 1]], ['consultation_id' => str_repeat('1', 26)])->assertUnprocessable()->assertJsonValidationErrors('prescription.consultation_id');
    ($this->sell)([['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 1]], ['consultation_id' => $done['id'], 'organization_id' => $this->w->c2->id])->assertUnprocessable();
});

it('answers a replaced prescription\'s code with the amendment once it is finished', function () {
    $first = ($this->finish)(($this->write)());
    $next = $this->asToken($this->doctor)->postJson(($this->org)("outpatient/serials/{$this->serial['id']}/consultation/amend"), ['reason' => 'Wrong dose'])->assertCreated()->json('data');
    // Until the amendment is finished, the first is the prescription.
    expect(($this->open)($first['id'])->json('data'))->toMatchArray(['id' => $first['id'], 'amended' => false]);
    ($this->open)($next['id'])->assertOk()->assertJsonPath('data.id', $first['id']);

    $saved = $this->asToken($this->doctor)->putJson(($this->org)("outpatient/serials/{$this->serial['id']}/consultation"), [
        'diagnosis' => 'Viral fever', 'medicines' => [['medicine_id' => $this->napa['id'], 'medicine_text' => 'Napa 500 mg', 'dose' => '1+1+1', 'duration' => '3 days']], 'base_version' => $next['version'],
    ])->assertOk()->json('data');
    $second = ($this->finish)($saved);
    $found = ($this->open)($first['id'])->assertOk()->json('data');
    expect($found)->toMatchArray(['id' => $second['id'], 'amended' => true])->and($found['medicines'])->toHaveCount(1)->and($found['medicines'][0]['dose'])->toBe('1+1+1');
    expect(($this->search)('Rahim')->json('data'))->toHaveCount(1);
});

it('finds prescriptions only as far back as the rule, and other branches only when patients are shared', function () {
    $done = ($this->finish)(($this->write)());
    $this->travelTo(CarbonImmutable::parse('2026-12-04 04:00:00', 'UTC'));
    // Tokens expire after a day: sign in again.
    $pharmacist = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inventory.view', 'pos.view', 'pos.sell', 'pharmacy.view', 'pharmacy.sell'], 'Pharmacist 2')), $this->w->c1);
    expect(($this->search)('Rahim', $pharmacist)->json('data'))->toBe([]);
    ($this->open)($done['id'], $pharmacist)->assertNotFound();
    orgRule($this->w->c1, 'pharmacy.prescription_days', 60);
    expect(($this->search)('Rahim', $pharmacist)->json('data'))->toHaveCount(1);

    // A counter at a branch: the company's chamber is another branch's when patients are not shared.
    [, $branchTill] = ($this->counterAt)($this->w->b1, 'B1', ($this->keeperAt)($this->w->c1), $pharmacist, $this->w->c1);
    ($this->open)($done['id'], $pharmacist, $branchTill)->assertOk();
    orgRule($this->w->c1, 'patients.share_between_branches', false);
    ($this->open)($done['id'], $pharmacist, $branchTill)->assertNotFound();
    expect(($this->search)('Rahim', $pharmacist, $branchTill)->json('data'))->toBe([]);
    ($this->open)($done['id'], $pharmacist)->assertOk();
});

it('lets only those who sell look, and only with Outpatient on', function () {
    $done = ($this->finish)(($this->write)());
    ($this->search)('Rahim', $this->cashier)->assertForbidden();
    ($this->open)($done['id'], $this->cashier)->assertForbidden();
    expect(collect($this->asToken($this->owner)->getJson(($this->org)("patients/patients/{$this->rahim['id']}/views"))->json('data'))->pluck('purpose')->all())->not->toContain('prescription');

    toggles()->disable($this->w->g1, 'outpatient', 'Test setup', confirm: true);
    ($this->open)($done['id'])->assertForbidden()->assertJsonPath('code', 'prescriptions_off');
    ($this->search)('Rahim')->assertForbidden()->assertJsonPath('code', 'prescriptions_off');
    $this->asToken($this->pharmacist)->getJson(($this->org)("pharmacy/counter/{$this->till['id']}"))->assertOk()->assertJsonPath('meta.prescriptions_on', false);
    ($this->sell)([['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 1]], ['consultation_id' => $done['id']])->assertUnprocessable()->assertJsonValidationErrors('prescription.consultation_id');
    // Selling without a prescription goes on as before.
    ($this->sell)([['medicine_id' => $this->napa['id'], 'pack' => 'strip', 'quantity' => 1]], [])->assertCreated();
});

it('never finds another company\'s or partner\'s prescriptions', function () {
    $done = ($this->finish)(($this->write)());
    setPlan($this->w->g3, 'business');
    foreach ($this->modules as $module) {
        toggles()->enable($this->w->g3, $module, 'Test setup');
    }
    foreach ([$this->w->c2, $this->w->c4] as $company) {
        $keeper = ($this->keeperAt)($company);
        $owner = orgToken(staffWithRoles($company, makeRole($company, ['inventory.view', 'pos.view', 'pos.sell', 'pharmacy.view', 'pharmacy.sell'], 'Pharmacist')), $company);
        setUpBooks($this, $keeper, $company)->assertCreated();
        [, $till] = ($this->counterAt)($company, 'X1', $keeper, $owner, $company);
        $this->asToken($owner)->getJson(($this->org)("pharmacy/counter/{$till['id']}/prescriptions/{$done['id']}", $company))->assertNotFound();
        expect($this->asToken($owner)->getJson(($this->org)("pharmacy/counter/{$till['id']}/prescriptions?search={$done['id']}", $company))->assertOk()->json('data'))->toBe([])
            ->and($this->asToken($owner)->getJson(($this->org)("pharmacy/counter/{$till['id']}/prescriptions?search=Rahim", $company))->assertOk()->json('data'))->toBe([]);
        // Another company's counter is not reachable from here either.
        $this->asToken($owner)->getJson(($this->org)("pharmacy/counter/{$this->till['id']}/prescriptions/{$done['id']}", $company))->assertNotFound();
    }
});
