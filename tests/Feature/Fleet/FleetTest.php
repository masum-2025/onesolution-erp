<?php

use Carbon\CarbonImmutable;

/*
 * Fleet (made by make:erp-module): each entity made once per tap, listed,
 * searched, read, changed with its version and removed; validation, the permission, the module off,
 * and another company's records. Grow these tests with the module.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    // A plan with every module (a new module is in no listed plan until someone adds it).
    setPlan($this->w->g1, 'business');
    foreach (['hrm', 'fleet'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['fleet.view'], 'Viewer')), $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/fleet/{$path}";
    $this->employee = hireVia($this, $this->token, $this->w->c1)->assertCreated()->json('data');
    $this->records = [];
    $this->records['vehicle'] = $this->asToken($this->token)->postJson(($this->api)('vehicles'), fleetPayload($this, 'vehicle', 1))->assertCreated()->json('data');
    $this->records['trip'] = $this->asToken($this->token)->postJson(($this->api)('trips'), fleetPayload($this, 'trip', 1))->assertCreated()->json('data');
});

it('keeps Vehicles: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('vehicles'), [...fleetPayload($this, 'vehicle', 2), 'op_id' => 'tap-vehicle'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('vehicles'), [...fleetPayload($this, 'vehicle', 2), 'op_id' => 'tap-vehicle'])->assertOk()->assertJsonPath('data.id', $made['id']);
    $this->asToken($this->token)->postJson(($this->api)('vehicles'), fleetPayload($this, 'vehicle', 2))->assertUnprocessable()->assertJsonValidationErrors('registration_no');
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('vehicles'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $found = $this->asToken($this->viewer)->getJson(($this->api)('vehicles?q='.urlencode(mb_substr((string) $made['registration_no'], 0, 6))))->assertOk()->json('data');
    expect(collect($found)->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("vehicles/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("vehicles/{$made['id']}"), ['registration_no' => 'Changed', 'base_version' => 1])->assertOk()->json('data');
    expect($changed['registration_no'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("vehicles/{$made['id']}"), ['registration_no' => 'Changed', 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("vehicles/{$made['id']}?base_version=2"))->assertForbidden();
    // Records of other entities link to the first one: it stays.
    $first = $this->records['vehicle'];
    $this->asToken($this->token)->deleteJson(($this->api)("vehicles/{$first['id']}?base_version=1"))->assertStatus(409)->assertJsonPath('code', 'in_use');
    $this->asToken($this->token)->deleteJson(($this->api)("vehicles/{$made['id']}?base_version=2"))->assertNoContent();
});

it('keeps Trips: made once per tap, listed, read, changed with their version, removed', function () {
    $made = $this->asToken($this->token)->postJson(($this->api)('trips'), [...fleetPayload($this, 'trip', 2), 'op_id' => 'tap-trip'])->assertCreated()->json('data');
    $this->asToken($this->token)->postJson(($this->api)('trips'), [...fleetPayload($this, 'trip', 2), 'op_id' => 'tap-trip'])->assertOk()->assertJsonPath('data.id', $made['id']);
    expect(collect($this->asToken($this->viewer)->getJson(($this->api)('trips'))->assertOk()->json('data'))->pluck('id'))->toContain($made['id']);
    $found = $this->asToken($this->viewer)->getJson(($this->api)('trips?q='.urlencode(mb_substr((string) $made['purpose'], 0, 6))))->assertOk()->json('data');
    expect(collect($found)->pluck('id'))->toContain($made['id']);
    $this->asToken($this->viewer)->getJson(($this->api)("trips/{$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    $changed = $this->asToken($this->token)->patchJson(($this->api)("trips/{$made['id']}"), ['purpose' => 'Changed', 'base_version' => 1])->assertOk()->json('data');
    expect($changed['purpose'])->toBe('Changed')->and($changed['version'])->toBe(2);
    $this->asToken($this->token)->patchJson(($this->api)("trips/{$made['id']}"), ['purpose' => 'Changed', 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->viewer)->deleteJson(($this->api)("trips/{$made['id']}?base_version=2"))->assertForbidden();
    $this->asToken($this->token)->deleteJson(($this->api)("trips/{$made['id']}?base_version=2"))->assertNoContent();
    $this->asToken($this->token)->getJson(($this->api)("trips/{$made['id']}"))->assertNotFound();
});

it('checks what is sent, keeps writing to managers, and answers 403 with the module off', function () {
    $this->asToken($this->token)->postJson(($this->api)('vehicles'), [])->assertUnprocessable()->assertJsonValidationErrors([
        0 => 'registration_no',
        1 => 'kind',
    ]);
    $this->asToken($this->viewer)->postJson(($this->api)('vehicles'), fleetPayload($this, 'vehicle', 6))->assertForbidden();
    $this->asToken($this->viewer)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', false);
    $this->asToken($this->token)->getJson(($this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', true);

    toggles()->disable($this->w->c1, 'fleet', 'Test');
    $this->asToken($this->token)->getJson(($this->api)('vehicles'))->assertForbidden();
});

it('never shows or changes another company\'s records', function () {
    $record = $this->records['vehicle'];
    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($other)->getJson(($this->api)("vehicles/{$record['id']}", $this->w->c2))->assertNotFound();
    $this->asToken($other)->patchJson(($this->api)("vehicles/{$record['id']}", $this->w->c2), ['base_version' => 1])->assertNotFound();
    $this->asToken($other)->getJson(($this->api)("vehicles/{$record['id']}"))->assertNotFound();
});

/** A full, valid record of an entity; $n makes text values differ. */
function fleetPayload($test, string $entity, int $n): array
{
    return match ($entity) {
        'vehicle' => [
            'registration_no' => mb_substr('Vehicle registration_no '.$n, 0, 30),
            'model' => mb_substr('Vehicle model '.$n, 0, 80),
            'kind' => 'truck',
            'capacity_milli' => 2500 * $n,
            'bought_on' => '2026-10-'.str_pad((string) min(28, $n + 10), 2, '0', STR_PAD_LEFT),
            'price_minor' => 125050 * $n,
            'in_use' => true,
            'notes' => 'Notes '.$n,
        ],
        'trip' => [
            'purpose' => mb_substr('Trip purpose '.$n, 0, 120),
            'vehicle_id' => $test->records['vehicle']['id'],
            'driver_id' => $test->employee['id'],
            'trip_on' => '2026-10-'.str_pad((string) min(28, $n + 10), 2, '0', STR_PAD_LEFT),
            'destination' => mb_substr('Trip destination '.$n, 0, 120),
            'distance_milli' => 2500 * $n,
            'fuel_cost_minor' => 125050 * $n,
        ],
    };
}
