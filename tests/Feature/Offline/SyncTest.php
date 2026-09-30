<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Models\QuarantinedOperation;
use App\Platform\Offline\Models\SyncOperationRecord;
use App\Platform\Offline\Services\DeviceService;
use App\Platform\Offline\SyncRecords;
use App\Platform\Tenancy\Databases\PlacementStatus;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Databases\TenantPlacement;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\FixtureCashReceipt;
use Tests\Fixtures\FixtureNoteSync;
use Tests\Fixtures\FixtureReceiptSync;
use Tests\Fixtures\FixtureSyncNote;

/*
 * Offline mode and secure sync (Phase 7): a device works offline under a
 * signed lease; /api/sync applies each change once, as a fresh request,
 * turns stale versions into conflicts, keeps money append-only, holds
 * changes from removed devices or people for an admin, and tells such
 * devices to wipe.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->w = tenancyWorld();
    app(SyncRecords::class)->register('crm', FixtureNoteSync::class);
    app(SyncRecords::class)->register('crm', FixtureReceiptSync::class);
    toggles()->enable($this->w->g1, 'offline_mode', 'Test setup');
    toggles()->enable($this->w->g1, 'crm', 'Test setup');

    $this->admin = createMember($this->w->c1, MembershipType::Owner);
    $this->clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['offline_mode.use', 'crm.manage'], 'Clerk'));
});

/** Sets up a device for offline work, as the app does while online. */
function offlineDevice(object $test, $user = null): array
{
    $user ??= $test->clerk;

    return $test->asToken(orgToken($user, $test->w->c1))
        ->postJson('/api/offline/devices', ['name' => 'Shop tablet', 'platform' => 'Android'])
        ->assertCreated()->json('data');
}

function op(array $device, string $kind, string $action, array $data = [], ?string $record = null, ?int $version = null, ?string $opId = null, ?string $madeAt = null): array
{
    return array_filter([
        'op_id' => $opId ?? (string) Str::ulid(),
        'kind' => $kind,
        'action' => $action,
        'record_id' => $record,
        'base_version' => $version,
        'data' => $data,
        'made_at' => $madeAt ?? CarbonImmutable::now()->toIso8601String(),
        'lease_id' => $device['lease_id'],
    ], fn ($value) => $value !== null);
}

function syncNow(object $test, array $device, array $operations, $user = null, ?string $cursor = null, ?string $lease = null, ?string $token = null): TestResponse
{
    return $test->asToken($token ?? orgToken($user ?? $test->clerk, $test->w->c1))->postJson('/api/sync', array_filter([
        'device_id' => $device['device']['id'],
        'lease' => $lease ?? $device['lease'],
        'cursor' => $cursor,
        'operations' => $operations,
    ], fn ($value) => $value !== null));
}

it('sets up a device with a signed lease: permissions, rules, until when', function () {
    $device = offlineDevice($this);

    expect($device['kinds'])->toContain('crm.note', 'crm.receipt')
        ->and($device['permissions'])->toContain('offline_mode.use')
        ->and($device['rules']['offline_mode.offline_lease_hours'])->toBe(24)
        ->and($device['expires_at'])->toBe('2026-10-06T09:00:00+00:00')
        ->and(AuditLog::query()->where('action', 'offline.device_registered')->exists())->toBeTrue();

    // What the browser needs to refuse early (Phase 7-2): money or not, the permission per action.
    expect($device['kind_info']['crm.receipt']['money'])->toBeTrue()
        ->and($device['kind_info']['crm.note']['money'])->toBeFalse()
        ->and($device['kind_info']['crm.note']['permissions'])->toHaveKeys(['create', 'update', 'delete']);

    // A sync hands back the renewed lease in the same shape.
    $answer = syncNow($this, $device, [])->assertOk()->json();
    expect($answer)->toHaveKeys(['lease', 'lease_id', 'expires_at', 'kinds', 'kind_info', 'permissions', 'rules', 'cursor'])
        ->and($answer['kinds'])->toBe($device['kinds']);
});

it('applies an offline change once, however often it is sent', function () {
    $device = offlineDevice($this);
    $create = op($device, 'crm.note', 'create', ['title' => 'Counted stock']);

    $first = syncNow($this, $device, [$create])->assertOk()->json('results');
    $again = syncNow($this, $device, [$create])->assertOk()->json('results');

    expect($first[$create['op_id']]['status'])->toBe('applied')
        ->and($again)->toBe($first)
        ->and(FixtureSyncNote::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(SyncOperationRecord::query()->count())->toBe(1);
});

it('turns a stale version into a conflict, never an overwrite', function () {
    $device = offlineDevice($this);
    $created = syncNow($this, $device, [$create = op($device, 'crm.note', 'create', ['title' => 'First'])])->json("results.{$create['op_id']}");

    // Someone else changed it meanwhile (version 2).
    $other = offlineDevice($this, $this->admin);
    syncNow($this, $other, [op($other, 'crm.note', 'update', ['title' => 'Changed online'], $created['record_id'], 1)], $this->admin)->assertOk();

    $result = syncNow($this, $device, [$stale = op($device, 'crm.note', 'update', ['title' => 'Mine'], $created['record_id'], 1)])->json("results.{$stale['op_id']}");

    expect($result['status'])->toBe('conflict')
        ->and($result['server'])->toBe(['title' => 'Changed online'])
        ->and(FixtureSyncNote::query()->withoutGlobalScopes()->sole()->title)->toBe('Changed online');
});

it('sends back what changed since the device last caught up, deletions too', function () {
    $device = offlineDevice($this);
    $response = syncNow($this, $device, [$create = op($device, 'crm.note', 'create', ['title' => 'A'])]);
    $cursor = $response->json('cursor');
    $id = $response->json("results.{$create['op_id']}.record_id");

    $this->travel(1)->minutes();
    $other = offlineDevice($this, $this->admin);
    syncNow($this, $other, [op($other, 'crm.note', 'delete', [], $id, 1)], $this->admin)->assertOk();

    $this->travel(1)->minutes();
    $notes = syncNow($this, $device, [], cursor: $cursor)->assertOk()->json('changes')['crm.note'];

    expect($notes)->toBe(['records' => [], 'deleted' => [$id]]);
});

it('refuses a sync with an expired lease, and changes made after it ended', function () {
    $device = offlineDevice($this);

    $this->travel(25)->hours();
    syncNow($this, $device, [])->assertStatus(401)->assertJsonPath('code', 'lease_expired')->assertJsonPath('renew_lease', true);

    // Back online: a new lease. A change stamped after the old lease ended is not accepted.
    $renewed = $this->asToken(orgToken($this->clerk, $this->w->c1))->postJson("/api/offline/devices/{$device['device']['id']}/lease")->assertOk()->json('data');
    $late = op($device, 'crm.note', 'create', ['title' => 'Too late'], madeAt: CarbonImmutable::now()->toIso8601String());

    syncNow($this, $device, [$late], lease: $renewed['lease'])->assertOk()
        ->assertJsonPath("results.{$late['op_id']}.code", 'made_after_lease');
});

it('rejects forged leases and other people\'s devices', function () {
    $device = offlineDevice($this);
    [$version, $body] = explode('.', $device['lease']);

    syncNow($this, $device, [], lease: "{$version}.{$body}.forged")->assertStatus(401)->assertJsonPath('code', 'lease_invalid');
    syncNow($this, $device, [], user: $this->admin)->assertNotFound()->assertJsonPath('code', 'device_not_found');
});

it('tells a removed device to wipe, and holds what it still sends', function () {
    $device = offlineDevice($this);
    $this->asToken(orgToken($this->admin, $this->w->c1))
        ->postJson("/api/organizations/{$this->w->c1->id}/offline/devices/{$device['device']['id']}/revoke", ['reason' => 'Lost tablet'])->assertOk();

    syncNow($this, $device, [$held = op($device, 'crm.note', 'create', ['title' => 'Offline work'])])
        ->assertStatus(410)
        ->assertJsonPath('wipe', true)
        ->assertJsonPath("results.{$held['op_id']}.status", 'quarantined');

    expect(QuarantinedOperation::query()->sole()->reason)->toBe('device_revoked')
        ->and(FixtureSyncNote::query()->withoutGlobalScopes()->count())->toBe(0);

    // The device confirms it cleared its data.
    $this->asToken(orgToken($this->clerk, $this->w->c1))->postJson("/api/offline/devices/{$device['device']['id']}/wiped")->assertOk()->assertJsonPath('data.wiped', true);
});

it('holds payments made offline by someone who no longer works here; an admin releases or discards them', function () {
    platformRule('offline_mode.allow_offline_payments', true);
    $device = offlineDevice($this);
    // The device already holds its sign-in from before; the membership then ends.
    $token = orgToken($this->clerk, $this->w->c1);

    OrganizationMembership::query()->where('user_id', $this->clerk->id)->update(['status' => MembershipStatus::Suspended]);

    $payment = op($device, 'crm.receipt', 'create', ['amount_minor' => 50000, 'currency_code' => 'BDT']);
    $other = op($device, 'crm.receipt', 'create', ['amount_minor' => 700, 'currency_code' => 'BDT']);
    syncNow($this, $device, [$payment, $other], token: $token)->assertStatus(410)->assertJsonPath('wipe', true)->assertJsonPath('code', 'membership_ended');

    $held = QuarantinedOperation::query()->orderBy('created_at')->get();
    expect($held)->toHaveCount(2)
        ->and($held->every(fn ($operation) => $operation->money && $operation->reason === 'membership_ended'))->toBeTrue()
        ->and(FixtureCashReceipt::query()->withoutGlobalScopes()->count())->toBe(0);

    $token = orgToken($this->admin, $this->w->c1);
    $this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}/offline")->assertOk()->assertJsonCount(2, 'data.held')->assertJsonPath('data.held.0.money', true);

    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/offline/held/{$held[0]->id}/release")
        ->assertOk()->assertJsonPath('data.status', 'applied');
    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/offline/held/{$held[1]->id}/discard", ['reason' => 'Duplicate'])->assertOk();
    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/offline/held/{$held[1]->id}/release")->assertStatus(422)->assertJsonPath('code', 'already_decided');

    expect(FixtureCashReceipt::query()->withoutGlobalScopes()->sole()->amount_minor)->toBe(50000)
        ->and(AuditLog::query()->where('action', 'offline.quarantine_released')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'offline.quarantine_discarded')->where('reason', 'Duplicate')->exists())->toBeTrue();
});

it('keeps money append-only and off unless allowed', function () {
    $device = offlineDevice($this);

    syncNow($this, $device, [$receipt = op($device, 'crm.receipt', 'create', ['amount_minor' => 100, 'currency_code' => 'BDT'])])
        ->assertJsonPath("results.{$receipt['op_id']}.code", 'offline_payments_off');

    platformRule('offline_mode.allow_offline_payments', true);
    $device = offlineDevice($this);
    $made = syncNow($this, $device, [$receipt = op($device, 'crm.receipt', 'create', ['amount_minor' => 100, 'currency_code' => 'BDT'])])->json("results.{$receipt['op_id']}");

    syncNow($this, $device, [$change = op($device, 'crm.receipt', 'update', ['amount_minor' => 1], $made['record_id'], 1)])
        ->assertJsonPath("results.{$change['op_id']}.code", 'append_only');
    expect(FixtureCashReceipt::query()->withoutGlobalScopes()->sole()->amount_minor)->toBe(100);
});

it('does not apply a change made under rules that have since changed', function () {
    $device = offlineDevice($this);
    platformRule('offline_mode.allow_offline_payments', true);

    syncNow($this, $device, [$note = op($device, 'crm.note', 'create', ['title' => 'Made under old rules'])])
        ->assertJsonPath("results.{$note['op_id']}.code", 'rules_changed');
});

it('wipes every device when offline mode is turned off, and holds their changes', function () {
    $device = offlineDevice($this);

    toggles()->disable($this->w->g1, 'offline_mode', 'Test setup', confirm: true);

    expect(Device::query()->find($device['device']['id'])->wipe_requested_at)->not->toBeNull();
    syncNow($this, $device, [$held = op($device, 'crm.note', 'create', ['title' => 'x y'])])
        ->assertStatus(410)->assertJsonPath('wipe', true)->assertJsonPath('code', 'module_off');
    expect(QuarantinedOperation::query()->sole()->reason)->toBe('module_off');
});

it('needs the permission to work offline', function () {
    $viewer = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['crm.manage'], 'Viewer'));

    $this->asToken(orgToken($viewer, $this->w->c1))->postJson('/api/offline/devices', ['name' => 'Phone'])
        ->assertForbidden()->assertJsonPath('code', 'not_allowed');
});

it('applies changes only with the module permission, and checks the data', function () {
    $noCrm = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['offline_mode.use'], 'Offline only'));
    $device = offlineDevice($this, $noCrm);

    syncNow($this, $device, [$note = op($device, 'crm.note', 'create', ['title' => 'Nope'])], $noCrm)
        ->assertJsonPath("results.{$note['op_id']}.code", 'forbidden');

    $device = offlineDevice($this);
    syncNow($this, $device, [$bad = op($device, 'crm.note', 'create', ['title' => ''])])
        ->assertJsonPath("results.{$bad['op_id']}.code", 'invalid')
        ->assertJsonPath("results.{$bad['op_id']}.errors.title.0", fn ($message) => is_string($message));
});

it('limits how many changes one sync carries', function () {
    platformRule('offline_mode.sync_batch_max', 2);
    $device = offlineDevice($this);

    syncNow($this, $device, [op($device, 'crm.note', 'create', ['title' => 'a b']), op($device, 'crm.note', 'create', ['title' => 'c d']), op($device, 'crm.note', 'create', ['title' => 'e f'])])
        ->assertStatus(422)->assertJsonPath('code', 'too_many');
});

it('keeps organizations and partners apart', function () {
    $device = offlineDevice($this);
    syncNow($this, $device, [op($device, 'crm.note', 'create', ['title' => 'Mine'])])->assertOk();

    // Another company's admin (same partner) and another partner's admin see and touch nothing of C1.
    toggles()->enable($this->w->g2, 'offline_mode', 'Test setup');
    toggles()->enable($this->w->g3, 'offline_mode', 'Test setup');
    foreach ([$this->w->c3, $this->w->c4] as $other) {
        $admin = createMember($other, MembershipType::Owner);
        $token = orgToken($admin, $other);
        $this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}/offline")->assertNotFound();
        $this->asToken($token)->postJson("/api/organizations/{$other->id}/offline/devices/{$device['device']['id']}/revoke")->assertNotFound();
    }

    // A C2 clerk's device cannot reach C1's records.
    toggles()->enable($this->w->g1, 'offline_mode', 'Test setup');
    $c2Clerk = staffWithRoles($this->w->c2, makeRole($this->w->c2, ['offline_mode.use', 'crm.manage'], 'Clerk'));
    $c2Device = $this->asToken(orgToken($c2Clerk, $this->w->c2))->postJson('/api/offline/devices', ['name' => 'C2 tablet'])->assertCreated()->json('data');
    $note = FixtureSyncNote::query()->withoutGlobalScopes()->sole();

    $this->asToken(orgToken($c2Clerk, $this->w->c2))->postJson('/api/sync', [
        'device_id' => $c2Device['device']['id'],
        'lease' => $c2Device['lease'],
        'operations' => [$steal = op($c2Device, 'crm.note', 'update', ['title' => 'Stolen'], $note->id, 1)],
    ])->assertOk()->assertJsonPath("results.{$steal['op_id']}.code", 'not_found');

    expect($note->fresh()->title)->toBe('Mine');
});

it('lets a person see and remove their own devices only', function () {
    $device = offlineDevice($this);

    $this->asToken(orgToken($this->clerk, $this->w->c1))->getJson('/api/me/devices')->assertOk()->assertJsonPath('data.0.name', 'Shop tablet');
    $this->asToken(orgToken($this->admin, $this->w->c1))->deleteJson("/api/me/devices/{$device['device']['id']}")->assertNotFound();
    $this->asToken(orgToken($this->clerk, $this->w->c1))->deleteJson("/api/me/devices/{$device['device']['id']}")->assertOk();

    expect(Device::query()->find($device['device']['id'])->mustWipe())->toBeTrue();
});

it('never applies a change twice, even when the platform\'s record of it was lost (Phase 10)', function () {
    $device = offlineDevice($this);
    $create = op($device, 'crm.note', 'create', ['title' => 'Counted stock']);

    $first = syncNow($this, $device, [$create])->assertOk()->json("results.{$create['op_id']}");
    // The main database's answer is gone (e.g. a crash right after the business write).
    SyncOperationRecord::query()->delete();
    $again = syncNow($this, $device, [$create])->assertOk()->json("results.{$create['op_id']}");

    expect($again)->toBe($first)
        ->and(FixtureSyncNote::query()->withoutGlobalScopes()->count())->toBe(1);
});

describe('with group G1 in its own database (Phase 10)', function () {
    beforeEach(function () {
        $this->dedicated = dedicatedTenantDatabase();
        placeClient($this->w->g1);
    });

    it('applies changes in the client\'s database and sends back its changes', function () {
        $device = offlineDevice($this);
        $response = syncNow($this, $device, [$create = op($device, 'crm.note', 'create', ['title' => 'Counted stock'])])->assertOk();
        $id = $response->json("results.{$create['op_id']}.record_id");

        syncNow($this, $device, [$update = op($device, 'crm.note', 'update', ['title' => 'Recounted'], $id, 1)])
            ->assertOk()->assertJsonPath("results.{$update['op_id']}.version", 2);
        $stale = op($device, 'crm.note', 'update', ['title' => 'Old'], $id, 1);
        syncNow($this, $device, [$stale])->assertJsonPath("results.{$stale['op_id']}.status", 'conflict');

        expect(DB::connection($this->dedicated)->table('fixture_sync_notes')->pluck('title')->all())->toBe(['Recounted'])
            ->and(DB::table('fixture_sync_notes')->count())->toBe(0)
            ->and(DB::connection($this->dedicated)->table('offline_applied_operations')->count())->toBe(2)
            ->and(syncNow($this, $device, [])->json('changes')['crm.note']['records'])->toBe([['id' => $id, 'title' => 'Recounted', 'version' => 2]]);
    });

    it('never applies a change twice across the two databases', function () {
        $device = offlineDevice($this);
        $create = op($device, 'crm.note', 'create', ['title' => 'Once']);

        $first = syncNow($this, $device, [$create])->json("results.{$create['op_id']}");
        SyncOperationRecord::query()->delete();

        expect(syncNow($this, $device, [$create])->json("results.{$create['op_id']}"))->toBe($first)
            ->and(DB::connection($this->dedicated)->table('fixture_sync_notes')->count())->toBe(1);
    });

    it('keeps changes on the device while the data moves, and still syncs the rest', function () {
        $device = offlineDevice($this);
        TenantPlacement::query()->update(['status' => PlacementStatus::Moving->value]);
        app(TenantDatabases::class)->forget();

        $create = op($device, 'crm.note', 'create', ['title' => 'Wait for me']);
        $response = syncNow($this, $device, [$create])->assertOk()
            ->assertJsonPath("results.{$create['op_id']}.status", 'retry_later')
            ->assertJsonPath("results.{$create['op_id']}.code", 'data_moving');

        expect($response->json())->toHaveKeys(['changes', 'lease', 'cursor'])
            ->and(SyncOperationRecord::query()->count())->toBe(0)
            ->and(DB::connection($this->dedicated)->table('fixture_sync_notes')->count())->toBe(0);

        // The move is over: the same change goes through.
        TenantPlacement::query()->update(['status' => PlacementStatus::Active->value]);
        app(TenantDatabases::class)->forget();
        syncNow($this, $device, [$create])->assertJsonPath("results.{$create['op_id']}.status", 'applied');
        expect(DB::connection($this->dedicated)->table('fixture_sync_notes')->count())->toBe(1);
    });

    it('keeps a held change held while the data moves', function () {
        $device = offlineDevice($this);
        app(DeviceService::class)->revoke(Device::query()->find($device['device']['id']), $this->admin);
        syncNow($this, $device, [op($device, 'crm.note', 'create', ['title' => 'Held'])])->assertStatus(410);
        $held = QuarantinedOperation::query()->sole();

        TenantPlacement::query()->update(['status' => PlacementStatus::Moving->value]);
        app(TenantDatabases::class)->forget();

        $this->asToken(orgToken($this->admin, $this->w->c1))->postJson("/api/organizations/{$this->w->c1->id}/offline/held/{$held->id}/release")
            ->assertStatus(503)->assertJsonPath('code', 'data_moving');

        expect($held->fresh()->status)->toBe(QuarantinedOperation::PENDING);
    });
});

it('discards held changes nobody decided on in time', function () {
    $device = offlineDevice($this);
    app(DeviceService::class)->revoke(Device::query()->find($device['device']['id']), $this->admin);
    syncNow($this, $device, [op($device, 'crm.note', 'create', ['title' => 'x y'])])->assertStatus(410);

    $this->travel(31)->days();
    $this->artisan('offline:discard-expired')->assertSuccessful();

    expect(QuarantinedOperation::query()->sole()->status)->toBe('discarded');
});
