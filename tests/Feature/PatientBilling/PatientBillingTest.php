<?php

use Carbon\CarbonImmutable;

/*
 * Patient billing (made by make:erp-module): each entity made once per tap, listed,
 * searched, read, changed with its version and removed; validation, the permission, the module off,
 * and another company's records. Grow these tests with the module.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    // A plan with every module (a new module is in no listed plan until someone adds it).
    setPlan($this->w->g1, 'business');
    foreach (['patients', 'patient_billing'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['patient_billing.view'], 'Viewer')), $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/patient-billing/{$path}";
    $this->records = [];
    $this->records['service'] = $this->asToken($this->token)->postJson(($this->api)('services'), patientBillingPayload($this, 'service', 1))->assertCreated()->json('data');
    $this->records['panel'] = $this->asToken($this->token)->postJson(($this->api)('panels'), patientBillingPayload($this, 'panel', 1))->assertCreated()->json('data');
});

it('keeps Services and prices: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('services'), [...patientBillingPayload($this, 'service', 2), 'op_id' => 'tap-service'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('services'), [...patientBillingPayload($this, 'service', 2), 'op_id' => 'tap-service'])->assertOk()->assertJsonPath('data.id', $made['id']);
    $this->asToken($this->token)->postJson(($this->api)('services'), patientBillingPayload($this, 'service', 2))->assertUnprocessable()->assertJsonValidationErrors('code');
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('services'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $found = $this->asToken($this->viewer)->getJson(($this->api)('services?q='.urlencode(mb_substr((string) $made['code'], 0, 6))))->assertOk()->json('data');
    expect(collect($found)->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("services/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("services/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertOk()->json('data');
    expect($changed['name']['en'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("services/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("services/{$made['id']}?base_version=2"))->assertForbidden();
    $this->asToken($this->token)->deleteJson(($this->api)("services/{$made['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->token)->getJson(($this->api)("services/{$made['id']}"))->assertNotFound();
});

it('keeps Corporate and panel clients: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('panels'), [...patientBillingPayload($this, 'panel', 2), 'op_id' => 'tap-panel'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('panels'), [...patientBillingPayload($this, 'panel', 2), 'op_id' => 'tap-panel'])->assertOk()->assertJsonPath('data.id', $made['id']);
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('panels'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $found = $this->asToken($this->viewer)->getJson(($this->api)('panels?q='.urlencode(mb_substr((string) $made['name'], 0, 6))))->assertOk()->json('data');
    expect(collect($found)->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("panels/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("panels/{$made['id']}"), ['name' => 'Changed', 'base_version' => 1])->assertOk()->json('data');
    expect($changed['name'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("panels/{$made['id']}"), ['name' => 'Changed', 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("panels/{$made['id']}?base_version=2"))->assertForbidden();
    $this->asToken($this->token)->deleteJson(($this->api)("panels/{$made['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->token)->getJson(($this->api)("panels/{$made['id']}"))->assertNotFound();
});

it('checks what is sent, keeps writing to managers, and answers 403 with the module off', function () {
    $this->asToken($this->token)->postJson(($this->api)('services'), [])->assertUnprocessable()->assertJsonValidationErrors([
        0 => 'code',
        1 => 'name',
        2 => 'category',
        3 => 'price_minor',
    ]);
    $this->asToken($this->viewer)->postJson(($this->api)('services'), patientBillingPayload($this, 'service', 6))->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', false);
    $this->asToken($this->token)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', true);

    toggles()->disable($this->w->c1, 'patient_billing', 'Test');
    $this->asToken($this->token)->getJson(($this->api)('services'))->assertForbidden();
});

it('never shows or changes another company\'s records', function () {
    $record = $this->records['service'];
    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($other)->getJson(($this->api)("services/{$record['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($other)->patchJson(($this->api)("services/{$record['id']}", $this->w->c2), ['base_version' => 1])->assertNotFound();
    $this->asToken($other)->getJson(($this->api)("services/{$record['id']}"))->assertNotFound();
});

/** A full, valid record of an entity; $n makes text values differ. */
function patientBillingPayload($test, string $entity, int $n): array
{
    return match ($entity) {
        'service' => [
            'code' => mb_substr('Service code '.$n, 0, 30),
            'name' => ['en' => mb_substr('Service '.$n, 0, 150), 'bn' => 'নমুনা '.$n],
            'category' => 'consultation',
            'price_minor' => 125050 * $n,
            'is_active' => true,
        ],
        'panel' => [
            'name' => mb_substr('Corporate or panel client name '.$n, 0, 150),
            'kind' => 'corporate',
            'credit_limit_minor' => 125050 * $n,
            'discount_percent' => 40 + $n,
            'is_active' => true,
        ],
    };
}
