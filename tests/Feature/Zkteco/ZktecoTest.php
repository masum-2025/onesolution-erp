<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Context\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Zkteco\Contracts\Terminal;
use Modules\Zkteco\Models\Device;
use Modules\Zkteco\Protocol\TerminalFactory;
use Modules\Zkteco\Protocol\ZkClient;

/*
 * ZKT-1: ZKTeco terminals. A device is added on a private address only
 * (the comm key never shown); a pull reads its users and logs (from
 * import_from on, once each), matches users by employee code, records
 * matched users' punches in staff attendance once each (also when the same
 * punch came from the machine's file), keeps the rest as unmatched until
 * matched by hand; an unreachable device says why; the scheduled command
 * reads devices when due; permission, module off and other companies.
 */

/** A terminal that answers from arrays (or not at all). */
class FakeTerminal implements Terminal
{
    public static array $users = [];

    public static array $logs = [];

    public static ?string $fails = null;

    public static int $connects = 0;

    public function connect(): void
    {
        self::$connects++;
        if (self::$fails !== null) {
            throw new RuntimeException(self::$fails);
        }
    }

    public function facts(): array
    {
        return ['serial' => '6583165000291', 'model' => 'F18/ID', 'users' => count(self::$users), 'logs' => count(self::$logs)];
    }

    public function users(): array
    {
        return self::$users;
    }

    public function attendance(): array
    {
        return self::$logs;
    }

    public function disconnect(): void {}
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2] as $group) {
        setPlan($group, 'business');
        foreach (['hrm', 'attendance', 'zkteco'] as $module) {
            toggles()->enable($group, $module, 'Test setup');
        }
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->zk = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/zkteco/{$path}";
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->rahima = hireVia($this, $this->token, $this->w->c1, ['joined_on' => '2026-01-01'])->assertCreated()->json('data');
    $this->parvej = hireVia($this, $this->token, $this->w->c1, ['full_name' => 'Parvej Hossain', 'joined_on' => '2026-01-01'])->assertCreated()->json('data');

    FakeTerminal::$fails = null;
    FakeTerminal::$connects = 0;
    // Rahima's user id on the terminal is her employee code; Parvej is "33" there.
    FakeTerminal::$users = [
        ['uid' => 1, 'user_id' => $this->rahima['employee_code'], 'name' => 'RAHIMA'],
        ['uid' => 12, 'user_id' => '33', 'name' => 'PARVEJ'],
    ];
    FakeTerminal::$logs = [
        ['user_id' => $this->rahima['employee_code'], 'at' => '2026-09-30 09:00:00', 'status' => 1, 'punch' => 255],
        ['user_id' => $this->rahima['employee_code'], 'at' => '2026-10-15 08:55:00', 'status' => 1, 'punch' => 255],
        ['user_id' => $this->rahima['employee_code'], 'at' => '2026-10-15 18:02:00', 'status' => 1, 'punch' => 255],
        ['user_id' => '33', 'at' => '2026-10-15 08:50:05', 'status' => 1, 'punch' => 255],
        ['user_id' => '33', 'at' => '2026-10-15 18:18:35', 'status' => 1, 'punch' => 255],
    ];
    app()->instance(TerminalFactory::class, new class extends TerminalFactory
    {
        public function for(Device $device): Terminal
        {
            return new FakeTerminal;
        }
    });
    $this->device = fn (array $extra = []) => ($this->as)()->postJson(($this->zk)('devices', $this->w->b1), [
        'name' => 'Front gate F18', 'host' => '192.168.1.201', 'import_from' => '2026-10-01', 'comm_key' => 1234, ...$extra,
    ]);
    $this->punches = fn (array $employee) => DB::table('att_punches')->where('employee_id', $employee['id'])->whereNull('voided_at')->orderBy('punched_at')->pluck('punched_at')->all();
});

it('adds a device on the office network only, and never shows its comm key', function () {
    $device = ($this->device)()->assertCreated()->json('data');
    expect($device)->toMatchArray(['unit_id' => $this->w->b1->id, 'host' => '192.168.1.201', 'port' => 4370, 'protocol' => 'udp', 'has_comm_key' => true, 'import_from' => '2026-10-01'])
        ->and($device)->not->toHaveKey('comm_key');
    expect(json_encode(AuditLog::query()->where('action', 'zkteco.device_added')->sole()->new_values))->not->toContain('1234');
    // The key is kept encrypted.
    expect(DB::table('zk_devices')->value('comm_key'))->not->toBe('1234')->and(Device::query()->withoutGlobalScopes()->first()->comm_key)->toBe('1234');

    foreach (['8.8.8.8', '127.0.0.1', 'device.example.com', '::1'] as $host) {
        ($this->device)(['host' => $host])->assertUnprocessable()->assertJsonValidationErrors('host');
    }
    ($this->device)()->assertUnprocessable()->assertJsonValidationErrors('host');
    ($this->device)(['host' => '192.168.1.202', 'organization_id' => 'x'])->assertUnprocessable();
    // A second device of the branch, on another address.
    ($this->device)(['name' => 'Back door', 'host' => '10.0.0.15', 'protocol' => 'tcp'])->assertCreated();
    expect(($this->as)()->getJson(($this->zk)('devices'))->assertOk()->json('data'))->toHaveCount(2);
});

it('brings punches into attendance once, by employee code, keeping the others until matched by hand', function () {
    $device = ($this->device)()->assertCreated()->json('data');
    $result = ($this->as)()->postJson(($this->zk)("devices/{$device['id']}/sync"))->assertOk()->json('data');
    // September's log is before import_from; Rahima's two are recorded; Parvej's two wait.
    expect($result['result'])->toBe(['read' => 5, 'new' => 4, 'recorded' => 2, 'unmatched' => 2, 'not_employed' => 0, 'users' => 2])
        ->and($result['device'])->toMatchArray(['serial' => '6583165000291', 'model' => 'F18/ID', 'last_error' => null])
        ->and(($this->punches)($this->rahima))->toHaveCount(2);
    // The terminal's clock is the company's local time (Dhaka, UTC+6): 08:55 there is 02:55 UTC.
    expect(($this->punches)($this->rahima)[0])->toStartWith('2026-10-15 02:55:00');

    // Again: nothing new, nothing twice.
    expect(($this->as)()->postJson(($this->zk)("devices/{$device['id']}/sync"))->json('data.result'))->toMatchArray(['new' => 0, 'recorded' => 0, 'unmatched' => 2])
        ->and(($this->punches)($this->rahima))->toHaveCount(2);

    $users = collect(($this->as)()->getJson(($this->zk)("devices/{$device['id']}/users"))->assertOk()->json('data'))->keyBy('user_id');
    expect($users[$this->rahima['employee_code']])->toMatchArray(['matched_by' => 'code', 'waiting' => 0])
        ->and($users['33'])->toMatchArray(['name' => 'PARVEJ', 'employee' => null, 'waiting' => 2]);
    // Matched by hand: the waiting punches go right away, and it is audited.
    ($this->as)()->patchJson(($this->zk)("devices/{$device['id']}/users/{$users['33']['id']}"), ['employee_id' => $this->parvej['id']])
        ->assertOk()->assertJsonPath('data.recorded', 2)->assertJsonPath('data.matched_by', 'hand');
    expect(($this->punches)($this->parvej))->toHaveCount(2)->and(AuditLog::query()->where('action', 'zkteco.user_matched')->count())->toBe(1);
    ($this->as)()->patchJson(($this->zk)("devices/{$device['id']}/users/{$users['33']['id']}"), ['employee_id' => str_repeat('A', 26)])
        ->assertUnprocessable()->assertJsonValidationErrors('employee_id');

    // The same punch brought in from the machine's file counts once.
    $csv = "No.,Time\n{$this->rahima['employee_code']},2026-10-15 08:55:00\n";
    $file = UploadedFile::fake()->createWithContent('att.csv', $csv);
    ($this->as)()->post("/api/organizations/{$this->w->c1->id}/attendance/device-import", ['file' => $file, 'columns' => ['code' => 'No.', 'datetime' => 'Time'], 'datetime_format' => 'Y-m-d H:i:s'], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.already', 1)->assertJsonPath('data.added', 0);
    expect(($this->punches)($this->rahima))->toHaveCount(2);

    // A device that brought punches is switched off, not removed.
    $fresh = collect(($this->as)()->getJson(($this->zk)('devices'))->json('data'))->firstWhere('id', $device['id']);
    ($this->as)()->deleteJson(($this->zk)("devices/{$device['id']}?base_version={$fresh['version']}"))->assertStatus(409)->assertJsonPath('code', 'has_logs');
    ($this->as)()->patchJson(($this->zk)("devices/{$device['id']}"), ['is_active' => false, 'base_version' => $fresh['version']])->assertOk()->assertJsonPath('data.is_active', false);
});

it('suggests who a device user is from the name it keeps, and matches many at once', function () {
    $device = ($this->device)()->assertCreated()->json('data');
    ($this->as)()->postJson(($this->zk)("devices/{$device['id']}/sync"))->assertOk();
    $list = ($this->as)()->getJson(($this->zk)("devices/{$device['id']}/users"))->assertOk();
    $users = collect($list->json('data'))->keyBy('user_id');
    // PARVEJ on the device is Parvej Hossain in HRM; Rahima is matched by code already.
    expect($users['33']['suggestion'])->toBe($this->parvej['id'])->and($users[$this->rahima['employee_code']]['suggestion'])->toBeNull()
        ->and(collect($list->json('meta.employees'))->pluck('id'))->toContain($this->parvej['id']);

    ($this->as)()->patchJson(($this->zk)("devices/{$device['id']}/users"), ['matches' => [['user' => $users['33']['id'], 'employee_id' => $this->parvej['id']]]])
        ->assertOk()->assertJsonPath('data', ['matched' => 1, 'recorded' => 2]);
    expect(($this->punches)($this->parvej))->toHaveCount(2);
    ($this->as)()->patchJson(($this->zk)("devices/{$device['id']}/users"), ['matches' => [['user' => str_repeat('Z', 26), 'employee_id' => null]]])->assertNotFound();
});

it('says why a device could not be read, and the command reads devices when they are due', function () {
    $device = ($this->device)()->assertCreated()->json('data');
    FakeTerminal::$fails = 'connect_failed: timed out';
    ($this->as)()->postJson(($this->zk)("devices/{$device['id']}/test"))->assertUnprocessable()
        ->assertJsonPath('code', 'unreachable')->assertJsonPath('reason', 'no_answer');
    expect(Device::query()->withoutGlobalScopes()->first()->last_error)->toBe('no_answer');
    FakeTerminal::$fails = 'wrong_comm_key';
    ($this->as)()->postJson(($this->zk)("devices/{$device['id']}/sync"))->assertUnprocessable()->assertJsonPath('reason', 'wrong_comm_key');
    FakeTerminal::$fails = null;
    ($this->as)()->postJson(($this->zk)("devices/{$device['id']}/test"))->assertOk()->assertJsonPath('data.facts.users', 2)->assertJsonPath('data.device.last_error', null);

    // Just tried: due again once the rule's five minutes have passed.
    FakeTerminal::$connects = 0;
    $this->artisan('zkteco:pull')->assertSuccessful();
    expect(FakeTerminal::$connects)->toBe(0);
    $this->travel(6)->minutes();
    $this->artisan('zkteco:pull')->assertSuccessful();
    expect(FakeTerminal::$connects)->toBe(1)->and(($this->punches)($this->rahima))->toHaveCount(2);
    // Read a minute ago: not due again until the rule's minutes pass.
    $this->artisan('zkteco:pull')->assertSuccessful();
    expect(FakeTerminal::$connects)->toBe(1);
    $this->travel(6)->minutes();
    $this->artisan('zkteco:pull')->assertSuccessful();
    expect(FakeTerminal::$connects)->toBe(2);
    // Off at the company: the command leaves it.
    toggles()->disable($this->w->c1, 'zkteco', 'Test');
    $this->travel(6)->minutes();
    $this->artisan('zkteco:pull')->assertSuccessful();
    expect(FakeTerminal::$connects)->toBe(2);
});

it('keeps one terminal user alone when asked (a first try on a real device), with no one signed in', function () {
    $device = ($this->device)()->assertCreated()->json('data');
    // As the scheduler runs it: no organization chosen (the real F18 found this).
    app(CurrentContext::class)->clear();
    $this->artisan('zkteco:pull', ['--device' => $device['id'], '--user' => '33'])->assertSuccessful();
    expect(DB::table('zk_logs')->distinct()->pluck('user_id')->all())->toBe(['33'])->and(($this->punches)($this->rahima))->toBe([]);
});

it('needs the permission, answers 403 with a module off, and never shows another company\'s devices', function () {
    $device = ($this->device)()->assertCreated()->json('data');
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['zkteco.view', 'hrm.view'], 'Viewer')), $this->w->c1);
    ($this->as)($viewer)->getJson(($this->zk)('devices'))->assertOk()->assertJsonCount(1, 'data');
    ($this->as)($viewer)->postJson(($this->zk)("devices/{$device['id']}/sync"))->assertForbidden();
    ($this->as)($viewer)->postJson(($this->zk)('devices', $this->w->b1), ['name' => 'Gate 2', 'host' => '192.168.1.9', 'import_from' => '2026-10-01'])->assertForbidden();

    $other = orgToken(createMember($this->w->c2), $this->w->c2);
    ($this->as)($other)->postJson(($this->zk)("devices/{$device['id']}/sync", $this->w->c2))->assertNotFound();
    ($this->as)($other)->getJson(($this->zk)("devices/{$device['id']}/users", $this->w->c2))->assertNotFound();
    expect(($this->as)($other)->getJson(($this->zk)('devices', $this->w->c2))->json('data'))->toBe([]);

    toggles()->disable($this->w->c1, 'zkteco', 'Test');
    ($this->as)()->getJson(($this->zk)('devices'))->assertForbidden();
});

it('reads the terminal\'s packed clock and checksums as the protocol writes them', function () {
    // 2026-10-15 08:55:00 packed the ZK way.
    $t = ((((26 * 12 + 9) * 31 + 14) * 24 + 8) * 60 + 55) * 60;
    expect(ZkClient::decodeTime($t))->toBe('2026-10-15 08:55:00');
    // CMD_CONNECT with nothing else: the checksum the K18 accepted (bytes 17 fc).
    expect(ZkClient::checksum(pack('vvvv', 1000, 0, 0, 0)))->toBe(0xFC17);
});
