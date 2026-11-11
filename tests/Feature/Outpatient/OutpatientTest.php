<?php

use Carbon\CarbonImmutable;

/*
 * Outpatient (made by make:erp-module): each entity made once per tap, listed,
 * searched, read, changed with its version and removed; validation, the permission, the module off,
 * and another company's records. Grow these tests with the module.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    // A plan with every module (a new module is in no listed plan until someone adds it).
    setPlan($this->w->g1, 'business');
    foreach (['patients', 'patient_billing', 'outpatient'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['outpatient.view'], 'Viewer')), $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/outpatient/{$path}";
    // Doctors take their fee from billing services: one pair per code the payloads use.
    foreach ([1, 2, 6] as $n) {
        foreach (['FEE', 'FUP'] as $code) {
            $this->asToken($this->token)->postJson('/api/organizations/'.$this->w->c1->id.'/patient-billing/services', [
                'code' => "{$code}-{$n}", 'name' => ['en' => "Fee {$n}"], 'category' => 'consultation', 'price_minor' => 50000, 'is_active' => true,
            ])->assertCreated();
        }
    }
    $this->records = [];
    $this->records['doctor'] = $this->asToken($this->token)->postJson(($this->api)('doctors'), outpatientPayload($this, 'doctor', 1))->assertCreated()->json('data');
    $this->records['chamber'] = $this->asToken($this->token)->postJson(($this->api)('chambers'), outpatientPayload($this, 'chamber', 1))->assertCreated()->json('data');
});

it('keeps Doctors: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('doctors'), [...outpatientPayload($this, 'doctor', 2), 'op_id' => 'tap-doctor'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('doctors'), [...outpatientPayload($this, 'doctor', 2), 'op_id' => 'tap-doctor'])->assertOk()->assertJsonPath('data.id', $made['id']);
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('doctors'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $found = $this->asToken($this->viewer)->getJson(($this->api)('doctors?q='.urlencode(mb_substr((string) $made['registration_no'], 0, 6))))->assertOk()->json('data');
    expect(collect($found)->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("doctors/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("doctors/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertOk()->json('data');
    expect($changed['name']['en'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("doctors/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("doctors/{$made['id']}?base_version=2"))->assertForbidden();
    $this->asToken($this->token)->deleteJson(($this->api)("doctors/{$made['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->token)->getJson(($this->api)("doctors/{$made['id']}"))->assertNotFound();
});

it('keeps Chambers: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('chambers'), [...outpatientPayload($this, 'chamber', 2), 'op_id' => 'tap-chamber'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('chambers'), [...outpatientPayload($this, 'chamber', 2), 'op_id' => 'tap-chamber'])->assertOk()->assertJsonPath('data.id', $made['id']);
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('chambers'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $found = $this->asToken($this->viewer)->getJson(($this->api)('chambers?q='.urlencode(mb_substr((string) $made['room'], 0, 6))))->assertOk()->json('data');
    expect(collect($found)->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("chambers/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("chambers/{$made['id']}"), ['name' => 'Changed', 'base_version' => 1])->assertOk()->json('data');
    expect($changed['name'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("chambers/{$made['id']}"), ['name' => 'Changed', 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("chambers/{$made['id']}?base_version=2"))->assertForbidden();
    $this->asToken($this->token)->deleteJson(($this->api)("chambers/{$made['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->token)->getJson(($this->api)("chambers/{$made['id']}"))->assertNotFound();
});

it('checks what is sent, keeps writing to managers, and answers 403 with the module off', function () {
    $this->asToken($this->token)->postJson(($this->api)('doctors'), [])->assertUnprocessable()->assertJsonValidationErrors([
        0 => 'name',
        1 => 'fee_service_code',
    ]);
    $this->asToken($this->viewer)->postJson(($this->api)('doctors'), outpatientPayload($this, 'doctor', 6))->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', false);
    $this->asToken($this->token)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', true);

    toggles()->disable($this->w->c1, 'outpatient', 'Test');
    $this->asToken($this->token)->getJson(($this->api)('doctors'))->assertForbidden();
});

it('never shows or changes another company\'s records', function () {
    $record = $this->records['doctor'];
    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($other)->getJson(($this->api)("doctors/{$record['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($other)->patchJson(($this->api)("doctors/{$record['id']}", $this->w->c2), ['base_version' => 1])->assertNotFound();
    $this->asToken($other)->getJson(($this->api)("doctors/{$record['id']}"))->assertNotFound();
});

/** A full, valid record of an entity; $n makes text values differ. */
function outpatientPayload($test, string $entity, int $n): array
{
    return match ($entity) {
        'doctor' => [
            'name' => ['en' => mb_substr('Doctor '.$n, 0, 150), 'bn' => 'নমুনা '.$n],
            'registration_no' => mb_substr('Doctor registration_no '.$n, 0, 30),
            'degrees' => mb_substr('Doctor degrees '.$n, 0, 200),
            'specialty' => mb_substr('Doctor specialty '.$n, 0, 150),
            'mobile' => mb_substr('Doctor mobile '.$n, 0, 20),
            'fee_service_code' => 'FEE-'.$n,
            'follow_up_service_code' => 'FUP-'.$n,
            'is_active' => true,
        ],
        'chamber' => [
            'name' => mb_substr('Chamber name '.$n, 0, 100),
            'room' => mb_substr('Chamber room '.$n, 0, 30),
            'is_active' => true,
        ],
    };
}
