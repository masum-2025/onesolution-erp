<?php

use Carbon\CarbonImmutable;

/*
 * Pharmacy (made by make:erp-module): each entity made once per tap, listed,
 * searched, read, changed with its version and removed; validation, the permission, the module off,
 * and another company's records. Grow these tests with the module.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    // A plan with every module (a new module is in no listed plan until someone adds it).
    setPlan($this->w->g1, 'business');
    foreach (['inventory', 'pos', 'pharmacy'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['pharmacy.view'], 'Viewer')), $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/pharmacy/{$path}";
    $this->records = [];
    $this->records['generic'] = $this->asToken($this->token)->postJson(($this->api)('generics'), pharmacyPayload($this, 'generic', 1))->assertCreated()->json('data');
    $this->records['manufacturer'] = $this->asToken($this->token)->postJson(($this->api)('manufacturers'), pharmacyPayload($this, 'manufacturer', 1))->assertCreated()->json('data');
});

it('keeps Generics: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('generics'), [...pharmacyPayload($this, 'generic', 2), 'op_id' => 'tap-generic'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('generics'), [...pharmacyPayload($this, 'generic', 2), 'op_id' => 'tap-generic'])->assertOk()->assertJsonPath('data.id', $made['id']);
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('generics'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $found = $this->asToken($this->viewer)->getJson(($this->api)('generics?q='.urlencode(mb_substr((string) $made['drug_class'], 0, 6))))->assertOk()->json('data');
    expect(collect($found)->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("generics/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("generics/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertOk()->json('data');
    expect($changed['name']['en'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("generics/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("generics/{$made['id']}?base_version=2"))->assertForbidden();
    $this->asToken($this->token)->deleteJson(($this->api)("generics/{$made['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->token)->getJson(($this->api)("generics/{$made['id']}"))->assertNotFound();
});

it('keeps Manufacturers: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('manufacturers'), [...pharmacyPayload($this, 'manufacturer', 2), 'op_id' => 'tap-manufacturer'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('manufacturers'), [...pharmacyPayload($this, 'manufacturer', 2), 'op_id' => 'tap-manufacturer'])->assertOk()->assertJsonPath('data.id', $made['id']);
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('manufacturers'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $found = $this->asToken($this->viewer)->getJson(($this->api)('manufacturers?q='.urlencode(mb_substr((string) $made['country'], 0, 6))))->assertOk()->json('data');
    expect(collect($found)->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("manufacturers/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("manufacturers/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertOk()->json('data');
    expect($changed['name']['en'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("manufacturers/{$made['id']}"), ['name' => ['en' => 'Changed', 'bn' => 'বদল'], 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("manufacturers/{$made['id']}?base_version=2"))->assertForbidden();
    $this->asToken($this->token)->deleteJson(($this->api)("manufacturers/{$made['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->token)->getJson(($this->api)("manufacturers/{$made['id']}"))->assertNotFound();
});

it('checks what is sent, keeps writing to managers, and answers 403 with the module off', function () {
    $this->asToken($this->token)->postJson(($this->api)('generics'), [])->assertUnprocessable()->assertJsonValidationErrors([
        0 => 'name',
    ]);
    $this->asToken($this->viewer)->postJson(($this->api)('generics'), pharmacyPayload($this, 'generic', 6))->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', false);
    $this->asToken($this->token)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', true);

    toggles()->disable($this->w->c1, 'pharmacy', 'Test');
    $this->asToken($this->token)->getJson(($this->api)('generics'))->assertForbidden();
});

it('never shows or changes another company\'s records', function () {
    $record = $this->records['generic'];
    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($other)->getJson(($this->api)("generics/{$record['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($other)->patchJson(($this->api)("generics/{$record['id']}", $this->w->c2), ['base_version' => 1])->assertNotFound();
    $this->asToken($other)->getJson(($this->api)("generics/{$record['id']}"))->assertNotFound();
});

/** A full, valid record of an entity; $n makes text values differ. */
function pharmacyPayload($test, string $entity, int $n): array
{
    return match ($entity) {
        'generic' => [
            'name' => ['en' => mb_substr('Generic '.$n, 0, 150), 'bn' => 'নমুনা '.$n],
            'drug_class' => mb_substr('Generic drug_class '.$n, 0, 60),
            'is_controlled' => true,
            'controlled_schedule' => mb_substr('Generic controlled_schedule '.$n, 0, 20),
            'in_use' => true,
        ],
        'manufacturer' => [
            'name' => ['en' => mb_substr('Manufacturer '.$n, 0, 150), 'bn' => 'নমুনা '.$n],
            'country' => mb_substr('Manufacturer country '.$n, 0, 2),
            'in_use' => true,
        ],
    };
}
