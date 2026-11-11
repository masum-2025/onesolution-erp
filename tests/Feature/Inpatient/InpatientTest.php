<?php

use Carbon\CarbonImmutable;

/*
 * Inpatient (made by make:erp-module): each entity made once per tap, listed,
 * searched, read, changed with its version and removed; validation, the permission, the module off,
 * and another company's records. Grow these tests with the module.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    // A plan with every module (a new module is in no listed plan until someone adds it).
    setPlan($this->w->g1, 'business');
    foreach (['patients', 'patient_billing', 'inpatient'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['inpatient.view'], 'Viewer')), $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/inpatient/{$path}";
    $this->records = [];
    $this->records['ward'] = $this->asToken($this->token)->postJson(($this->api)('wards'), inpatientPayload($this, 'ward', 1))->assertCreated()->json('data');
});

it('keeps Wards and cabins: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('wards'), [...inpatientPayload($this, 'ward', 2), 'op_id' => 'tap-ward'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('wards'), [...inpatientPayload($this, 'ward', 2), 'op_id' => 'tap-ward'])->assertOk()->assertJsonPath('data.id', $made['id']);
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('wards'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("wards/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("wards/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertOk()->json('data');
    expect($changed['name']['en'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("wards/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("wards/{$made['id']}?base_version=2"))->assertForbidden();
    $this->asToken($this->token)->deleteJson(($this->api)("wards/{$made['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->token)->getJson(($this->api)("wards/{$made['id']}"))->assertNotFound();
});

it('checks what is sent, keeps writing to managers, and answers 403 with the module off', function () {
    $this->asToken($this->token)->postJson(($this->api)('wards'), [])->assertUnprocessable()->assertJsonValidationErrors([
        0 => 'name',
        1 => 'kind',
    ]);
    $this->asToken($this->viewer)->postJson(($this->api)('wards'), inpatientPayload($this, 'ward', 6))->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', false);
    $this->asToken($this->token)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', true);

    toggles()->disable($this->w->c1, 'inpatient', 'Test');
    $this->asToken($this->token)->getJson(($this->api)('wards'))->assertForbidden();
});

it('never shows or changes another company\'s records', function () {
    $record = $this->records['ward'];
    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($other)->getJson(($this->api)("wards/{$record['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($other)->patchJson(($this->api)("wards/{$record['id']}", $this->w->c2), ['base_version' => 1])->assertNotFound();
    $this->asToken($other)->getJson(($this->api)("wards/{$record['id']}"))->assertNotFound();
});

/** A full, valid record of an entity; $n makes text values differ. */
function inpatientPayload($test, string $entity, int $n): array
{
    return match ($entity) {
        'ward' => [
            'name' => ['en' => mb_substr('Ward or cabin '.$n, 0, 100), 'bn' => 'নমুনা '.$n],
            'kind' => 'general',
            'is_active' => true,
        ],
    };
}
