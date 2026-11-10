<?php

use Carbon\CarbonImmutable;

/*
 * Diagnostics (made by make:erp-module): each entity made once per tap, listed,
 * searched, read, changed with its version and removed; validation, the permission, the module off,
 * and another company's records. Grow these tests with the module.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    // A plan with every module (a new module is in no listed plan until someone adds it).
    setPlan($this->w->g1, 'business');
    foreach (['patients', 'patient_billing', 'diagnostics'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['diagnostics.view'], 'Viewer')), $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/diagnostics/{$path}";
    // Tests take their price from billing services: one per code the payloads use.
    foreach ([1, 2, 6] as $n) {
        $this->asToken($this->token)->postJson('/api/organizations/'.$this->w->c1->id.'/patient-billing/services', [
            'code' => mb_substr('Test service_code '.$n, 0, 30), 'name' => ['en' => 'Service '.$n], 'category' => 'test', 'price_minor' => 10000, 'is_active' => true,
        ])->assertCreated();
    }
    $this->records = [];
    $this->records['test'] = $this->asToken($this->token)->postJson(($this->api)('tests'), diagnosticsPayload($this, 'test', 1))->assertCreated()->json('data');
    $this->records['referrer'] = $this->asToken($this->token)->postJson(($this->api)('referrers'), diagnosticsPayload($this, 'referrer', 1))->assertCreated()->json('data');
});

it('keeps Tests: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('tests'), [...diagnosticsPayload($this, 'test', 2), 'op_id' => 'tap-test'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('tests'), [...diagnosticsPayload($this, 'test', 2), 'op_id' => 'tap-test'])->assertOk()->assertJsonPath('data.id', $made['id']);
    $this->asToken($this->token)->postJson(($this->api)('tests'), diagnosticsPayload($this, 'test', 2))->assertUnprocessable()->assertJsonValidationErrors('code');
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('tests'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $found = $this->asToken($this->viewer)->getJson(($this->api)('tests?q='.urlencode(mb_substr((string) $made['code'], 0, 6))))->assertOk()->json('data');
    expect(collect($found)->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("tests/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("tests/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertOk()->json('data');
    expect($changed['name']['en'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("tests/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("tests/{$made['id']}?base_version=2"))->assertForbidden();
    $this->asToken($this->token)->deleteJson(($this->api)("tests/{$made['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->token)->getJson(($this->api)("tests/{$made['id']}"))->assertNotFound();
});

it('keeps Referring doctors: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('referrers'), [...diagnosticsPayload($this, 'referrer', 2), 'op_id' => 'tap-referrer'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('referrers'), [...diagnosticsPayload($this, 'referrer', 2), 'op_id' => 'tap-referrer'])->assertOk()->assertJsonPath('data.id', $made['id']);
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('referrers'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $found = $this->asToken($this->viewer)->getJson(($this->api)('referrers?q='.urlencode(mb_substr((string) $made['name'], 0, 6))))->assertOk()->json('data');
    expect(collect($found)->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("referrers/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("referrers/{$made['id']}"), ['name' => 'Changed', 'base_version' => 1])->assertOk()->json('data');
    expect($changed['name'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("referrers/{$made['id']}"), ['name' => 'Changed', 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("referrers/{$made['id']}?base_version=2"))->assertForbidden();
    $this->asToken($this->token)->deleteJson(($this->api)("referrers/{$made['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->token)->getJson(($this->api)("referrers/{$made['id']}"))->assertNotFound();
});

it('checks what is sent, keeps writing to managers, and answers 403 with the module off', function () {
    $this->asToken($this->token)->postJson(($this->api)('tests'), [])->assertUnprocessable()->assertJsonValidationErrors([
        0 => 'code',
        1 => 'name',
        2 => 'department',
        3 => 'sample_type',
        4 => 'service_code',
    ]);
    $this->asToken($this->viewer)->postJson(($this->api)('tests'), diagnosticsPayload($this, 'test', 6))->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', false);
    $this->asToken($this->token)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', true);

    toggles()->disable($this->w->c1, 'diagnostics', 'Test');
    $this->asToken($this->token)->getJson(($this->api)('tests'))->assertForbidden();
});

it('never shows or changes another company\'s records', function () {
    $record = $this->records['test'];
    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($other)->getJson(($this->api)("tests/{$record['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($other)->patchJson(($this->api)("tests/{$record['id']}", $this->w->c2), ['base_version' => 1])->assertNotFound();
    $this->asToken($other)->getJson(($this->api)("tests/{$record['id']}"))->assertNotFound();
});

/** A full, valid record of an entity; $n makes text values differ. */
function diagnosticsPayload($test, string $entity, int $n): array
{
    return match ($entity) {
        'test' => [
            'code' => mb_substr('Test code '.$n, 0, 30),
            'name' => ['en' => mb_substr('Test '.$n, 0, 150), 'bn' => 'নমুনা '.$n],
            'department' => 'haematology',
            'sample_type' => 'blood',
            'service_code' => mb_substr('Test service_code '.$n, 0, 30),
            'turnaround_hours' => 40 + $n,
            'is_active' => true,
        ],
        'referrer' => [
            'name' => mb_substr('Referring doctor name '.$n, 0, 150),
            'registration_no' => mb_substr('Referring doctor registration_no '.$n, 0, 30),
            'mobile' => mb_substr('Referring doctor mobile '.$n, 0, 20),
            'commission_percent' => 40 + $n,
            'is_active' => true,
        ],
    };
}
