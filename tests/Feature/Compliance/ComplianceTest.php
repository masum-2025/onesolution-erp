<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Modules\Compliance\Models\ReminderSent;
use Modules\Compliance\Services\Reminders;
use Modules\Compliance\Support\Validity;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
 * CMP-1: the audit register of a group of factories (G1: companies C1 > B1
 * and C2 > B2; G1 in Dhaka). Lists are kept by the group, lines per factory
 * and service; days left always from the official expiry day and the
 * company's today; official dates only by people allowed, with a reason;
 * reminders once per stage and expiry day; Excel or CSV import.
 */

beforeEach(function () {
    // Wednesday 28 October 2026, 20:00 UTC: already Thursday the 29th in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-10-28 20:00:00', 'UTC'));
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'business');
        toggles()->enable($group, 'compliance', 'Test setup');
    }
    $this->person = $person = function (Organization $at, array $permissions, string $name) {
        $user = createMember($at, MembershipType::Staff, AccessScope::Descendants);
        giveRoles($user, $at, makeRole($at, $permissions, $name));

        return $user;
    };
    // The group reads everything below it and keeps the group's lists; a factory's lines are
    // written at its company (a group context never writes into its companies' data).
    $this->gmUser = $person($this->w->g1, ['compliance.view', 'compliance.manage', 'compliance.set_expiry'], 'Group GM HRAC');
    $this->gm = orgToken($this->gmUser, $this->w->g1);
    $this->viewer = orgToken($person($this->w->g1, ['compliance.view'], 'Director'), $this->w->g1);
    $this->hracUser = $person($this->w->c1, ['compliance.view', 'compliance.manage', 'compliance.set_expiry'], 'HRAC C1');
    $this->hrac = orgToken($this->hracUser, $this->w->c1);
    $this->hracC2 = orgToken($person($this->w->c2, ['compliance.view', 'compliance.manage', 'compliance.set_expiry'], 'HRAC C2'), $this->w->c2);
    $this->officer = orgToken($person($this->w->c1, ['compliance.view', 'compliance.manage'], 'Compliance officer'), $this->w->c1);
    $this->api = fn (string $path, ?Organization $at = null) => '/api/organizations/'.($at ?? $this->w->g1)->id."/compliance/{$path}";

    $this->asToken($this->gm)->postJson(($this->api)('lists/services/defaults'))->assertOk()->assertJsonPath('data.added', 11);
    $this->asToken($this->gm)->postJson(($this->api)('lists/types/defaults'))->assertOk()->assertJsonPath('data.added', 5);
    $this->service = fn (string $code) => collect($this->asToken($this->gm)->getJson(($this->api)('lists/services'))->json('data'))->firstWhere('code', $code)['id'];
    // A line at a factory, by its company's people (C1's unless the factory is under C2).
    $this->companyOf = fn (Organization $factory) => str_starts_with($factory->path, $this->w->c2->path) ? $this->w->c2 : $this->w->c1;
    $this->line = function (Organization $factory, string $code, array $data = [], ?string $token = null) {
        // The service first: looking it up signs in as the GM.
        $service = ($this->service)($code);

        return $this->asToken($token ?? (($this->companyOf)($factory)->is($this->w->c2) ? $this->hracC2 : $this->hrac))
            ->postJson(($this->api)('audits', ($this->companyOf)($factory)), ['unit_id' => $factory->id, 'service_id' => $service, ...$data]);
    };
    // The daily command runs with no one signed in: the last request's context is set aside first.
    $this->remind = function () {
        app()->forgetScopedInstances();

        return app(Reminders::class)->run();
    };
});

it('works days left and the state out from the expiry day and the company\'s today', function () {
    $today = CarbonImmutable::parse('2026-10-29', 'UTC');
    expect(Validity::daysLeft(CarbonImmutable::parse('2026-10-29'), $today))->toBe(0)
        ->and(Validity::state(CarbonImmutable::parse('2026-10-29'), $today, 60))->toBe('expiring')
        ->and(Validity::state(CarbonImmutable::parse('2026-10-28'), $today, 60))->toBe('expired')
        ->and(Validity::state(CarbonImmutable::parse('2026-12-29'), $today, 60))->toBe('valid')
        ->and(Validity::state(null, $today, 60))->toBe('unknown')
        ->and(Validity::stage(CarbonImmutable::parse('2026-11-20'), $today, [60, 30, 15, 7]))->toBe(30)
        ->and(Validity::stage(CarbonImmutable::parse('2026-11-03'), $today, [60, 30, 15, 7]))->toBe(7)
        ->and(Validity::stage(CarbonImmutable::parse('2026-10-20'), $today, [60, 30, 15, 7]))->toBe(-1)
        ->and(Validity::stage(CarbonImmutable::parse('2027-10-20'), $today, [60, 30, 15, 7]))->toBeNull();

    ($this->line)($this->w->b1, 'BSCI', ['last_audit_on' => '2025-10-29', 'expires_on' => '2026-10-29'])->assertCreated()
        ->assertJsonPath('data.days_left', 0)->assertJsonPath('data.state', 'expiring');
    ($this->line)($this->w->b1, 'WRAP', ['expires_on' => '2026-10-28'])->assertCreated()->assertJsonPath('data.state', 'expired');
    ($this->line)($this->w->c2, 'ISO45001', ['expires_on' => '2027-06-01'])->assertCreated()->assertJsonPath('data.state', 'valid');
    ($this->line)($this->w->c1, 'GOTS')->assertCreated()->assertJsonPath('data.state', 'unknown')->assertJsonPath('data.days_left', null);

    $register = $this->asToken($this->viewer)->getJson(($this->api)('register'))->assertOk();
    expect(array_column($register->json('data'), 'state'))->toBe(['expired', 'expiring', 'valid', 'unknown'])
        ->and($register->json('meta.summary'))->toBe(['valid' => 1, 'expiring' => 1, 'expired' => 1, 'unknown' => 1])
        ->and(array_column($this->asToken($this->viewer)->getJson(($this->api)('register?state=expired'))->json('data'), 'state'))->toBe(['expired'])
        ->and($this->asToken($this->viewer)->getJson(($this->api)('register?unit_id='.$this->w->c1->id))->json('data'))->toHaveCount(3);
});

it('keeps the official dates to people allowed, always with a reason, and keeps every change', function () {
    // Without set_expiry: a line without dates, never with them.
    ($this->line)($this->w->b1, 'BSCI', ['expires_on' => '2027-01-31'], $this->officer)->assertForbidden()->assertJsonPath('code', 'dates_need_permission');
    $line = ($this->line)($this->w->b1, 'BSCI', ['result' => 'B'], $this->officer)->assertCreated()->json('data');
    $this->asToken($this->officer)->postJson(($this->api)("audits/{$line['id']}/dates", $this->w->c1), ['base_version' => $line['version'], 'expires_on' => '2027-01-31', 'reason' => 'New certificate'])
        ->assertForbidden();
    $this->asToken($this->viewer)->patchJson(($this->api)("audits/{$line['id']}"), ['base_version' => $line['version'], 'result' => 'A'])->assertForbidden();

    $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/dates", $this->w->c1), ['base_version' => $line['version'], 'expires_on' => '2027-01-31'])->assertStatus(422);
    $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/dates", $this->w->c1), ['base_version' => $line['version'], 'last_audit_on' => '2027-02-01', 'expires_on' => '2027-01-31', 'reason' => 'New certificate'])
        ->assertStatus(422)->assertJsonPath('code', 'dates_order');
    $dated = $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/dates", $this->w->c1), ['base_version' => $line['version'], 'last_audit_on' => '2026-01-31', 'expires_on' => '2027-01-31', 'reason' => 'New certificate received'])
        ->assertOk()->assertJsonPath('data.expires_on', '2027-01-31')->json('data');
    expect($dated['history'][0])->toMatchArray(['kind' => 'dates', 'reason' => 'New certificate received', 'by' => $this->hracUser->name])
        ->and($dated['history'][0]['new'])->toEqual(['last_audit_on' => '2026-01-31', 'expires_on' => '2027-01-31'])
        ->and(AuditLog::query()->where('action', 'compliance.dates_set')->where('organization_id', $this->w->b1->id)->count())->toBe(1);

    // Stale versions, a second line of the same service, a group or a department as the factory: refused.
    $this->asToken($this->hrac)->postJson(($this->api)("audits/{$line['id']}/dates", $this->w->c1), ['base_version' => $line['version'], 'expires_on' => '2027-02-28', 'reason' => 'Again please'])
        ->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    ($this->line)($this->w->b1, 'BSCI')->assertStatus(409)->assertJsonPath('code', 'already_in_register');
    ($this->line)($this->w->g1, 'WRAP')->assertStatus(422)->assertJsonPath('code', 'not_factory');
    ($this->line)($this->w->d1, 'WRAP')->assertStatus(422)->assertJsonPath('code', 'not_factory');
});

it('keeps lists at the group for its companies; a company adds its own, never changes the group\'s', function () {
    $this->asToken($this->gm)->postJson(($this->api)('lists/services/defaults'))->assertOk()->assertJsonPath('data.added', 0);
    $c1 = $this->hrac;
    $services = $this->asToken($c1)->getJson(($this->api)('lists/services', $this->w->c1))->assertOk()->json('data');
    expect($services)->toHaveCount(11)->and($services[0])->toMatchArray(['own' => false, 'kept_by' => 'G1']);

    $this->asToken($c1)->postJson(($this->api)('lists/services', $this->w->c1), ['code' => 'BSCI', 'name' => ['en' => 'BSCI again']])->assertStatus(422)->assertJsonPath('code', 'code_taken');
    $this->asToken($c1)->postJson(($this->api)('lists/services', $this->w->c1), ['code' => 'BUYER_CoC', 'name' => ['en' => 'Buyer code of conduct', 'bn' => 'ক্রেতার আচরণবিধি'], 'validity_months' => 12])
        ->assertCreated()->assertJsonPath('data.own', true);
    $this->asToken($c1)->patchJson(($this->api)("lists/services/{$services[0]['id']}", $this->w->c1), ['base_version' => $services[0]['version'], 'is_active' => false])
        ->assertStatus(422)->assertJsonPath('code', 'not_list_owner');
    // The group does not see a company's own items; its own switched off are listed with ?all.
    expect($this->asToken($this->gm)->getJson(($this->api)('lists/services'))->json('data'))->toHaveCount(11);
    $this->asToken($this->gm)->postJson(($this->api)('lists/providers'), ['name' => 'Apex Compliance Services', 'email' => 'audit@apex.test'])->assertCreated();
    $this->asToken($this->viewer)->postJson(($this->api)('lists/providers'), ['name' => 'Nobody'])->assertForbidden();
});

it('reminds once per stage and expiry day, again for a new expiry day, only while the module is on', function () {
    ($this->line)($this->w->b1, 'BSCI', ['expires_on' => '2026-11-20'])->assertCreated();   // 22 days: stage 30
    $wrap = ($this->line)($this->w->b1, 'WRAP', ['expires_on' => '2026-11-03'])->assertCreated()->json('data'); // 5 days: stage 7
    ($this->line)($this->w->c2, 'GOTS', ['expires_on' => '2026-10-01'])->assertCreated();   // expired
    ($this->line)($this->w->c2, 'FIRE', ['expires_on' => '2027-10-01'])->assertCreated();   // nothing yet

    expect(($this->remind)())->toBe(3)
        ->and(ReminderSent::query()->withoutGlobalScopes()->pluck('stage')->sort()->values()->all())->toBe([-1, 7, 30])
        ->and(NotificationDelivery::query()->where('notification_key', 'compliance.expiring')->count())->toBeGreaterThanOrEqual(2);
    expect(($this->remind)())->toBe(0);

    // A new expiry day for WRAP (23 November: 25 days) starts its stages again.
    $this->asToken($this->hrac)->postJson(($this->api)("audits/{$wrap['id']}/dates", $this->w->c1), ['base_version' => $wrap['version'], 'expires_on' => '2026-11-23', 'reason' => 'Extension letter'])->assertOk();
    expect(($this->remind)())->toBe(1);

    toggles()->disable($this->w->g1, 'compliance', 'Test');
    $this->travel(30)->days();
    expect(($this->remind)())->toBe(0);
});

it('imports an Excel file: checked first, then lines added and updated with the file as the reason', function () {
    ($this->line)($this->w->c1, 'WRAP', ['expires_on' => '2026-12-01'])->assertCreated();
    $path = tempnam(sys_get_temp_dir(), 'cmp').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ([
        ['factory', 'service', 'provider', 'audit_type', 'last_audit_on', 'expires_on', 'result', 'certificate_no'],
        ['B1', 'BSCI', 'Apex Compliance Services', 'renewal', '2026-03-15', '15/03/2027', 'B', 'BSCI-1'],
        ['c1', 'wrap', '', '', '', '2027-12-01', 'Gold', ''],
        ['B1', 'Higg FEM', '', '', new DateTimeImmutable('2026-05-01'), new DateTimeImmutable('2027-05-01'), '', ''],
        ['Nowhere', 'BSCI', '', '', '', '2027-01-01', '', ''],
        ['B1', 'NOPE', '', '', '', 'soon', '', ''],
        ['B1', 'BSCI', '', '', '', '2027-01-01', '', ''],
        ['C1', 'ISO9001', '', '', '', '', '=HYPERLINK("x")', ''],
    ] as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();
    $file = fn () => new UploadedFile($path, 'register.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

    $checked = $this->asToken($this->hrac)->post(($this->api)('import', $this->w->c1), ['file' => $file(), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk()->json('data');
    $problems = collect($checked['rows'])->filter(fn ($row) => $row['errors'] !== [])->mapWithKeys(fn ($row) => [$row['row_no'] => array_keys($row['errors'])])->all();
    expect($checked['total'])->toBe(7)->and($checked['valid'])->toBe(3)
        ->and($problems)->toBe([5 => ['factory'], 6 => ['service', 'expires_on'], 7 => ['service'], 8 => ['result']])
        ->and(collect($checked['rows'])->firstWhere('row_no', 2))->toMatchArray(['action' => 'add', 'new_provider' => true])
        ->and(collect($checked['rows'])->firstWhere('row_no', 3)['action'])->toBe('update');

    // Nothing stored by checking; importing with problems needs them skipped.
    expect($this->asToken($this->hrac)->getJson(($this->api)('register', $this->w->c1))->json('data'))->toHaveCount(1);
    $this->asToken($this->hrac)->post(($this->api)('import', $this->w->c1), ['file' => $file(), 'dry_run' => '0'], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'import_has_errors');
    $this->asToken($this->officer)->post(($this->api)('import', $this->w->c1), ['file' => $file(), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertForbidden();
    $done = $this->asToken($this->hrac)->post(($this->api)('import', $this->w->c1), ['file' => $file(), 'dry_run' => '0', 'skip_invalid' => '1'], ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($done)->toBe(['added' => 2, 'updated' => 1, 'unchanged' => 0, 'skipped' => 4]);

    $lines = collect($this->asToken($this->hrac)->getJson(($this->api)('register', $this->w->c1))->json('data'))->keyBy(fn ($line) => $line['factory']['name'].' '.$line['service']['code']);
    expect($lines['B1 BSCI'])->toMatchArray(['expires_on' => '2027-03-15', 'last_audit_on' => '2026-03-15', 'result' => 'B'])
        ->and($lines['B1 BSCI']['provider']['name'])->toBe('Apex Compliance Services')
        ->and($lines['B1 HIGG_FEM']['expires_on'])->toBe('2027-05-01')
        ->and($lines['C1 WRAP'])->toMatchArray(['expires_on' => '2027-12-01', 'result' => 'Gold']);
    $history = $this->asToken($this->hrac)->getJson(($this->api)("audits/{$lines['C1 WRAP']['id']}", $this->w->c1))->json('data.history');
    expect(collect($history)->firstWhere('kind', 'imported')['reason'])->toBe('Imported from register.xlsx');

    // A CSV works the same way; the template is an Excel file.
    $csv = UploadedFile::fake()->createWithContent('more.csv', "\xEF\xBB\xBFfactory,service,expires_on\nC1,ISO9001,2028-01-31\n");
    $this->asToken($this->hrac)->post(($this->api)('import', $this->w->c1), ['file' => $csv, 'dry_run' => '0'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.added', 1);
    $this->asToken($this->viewer)->get(($this->api)('import/template'))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('keeps the register to its factories, the people allowed and the module being on', function () {
    $line = ($this->line)($this->w->b1, 'BSCI', ['expires_on' => '2027-01-31'])->assertCreated()->json('data');

    // A sibling company's people and another client never see it.
    $c2 = orgToken(($this->person)($this->w->c2, ['compliance.view'], 'C2 viewer'), $this->w->c2);
    expect($this->asToken($c2)->getJson(($this->api)('register', $this->w->c2))->assertOk()->json('data'))->toBe([]);
    $this->asToken($c2)->getJson(($this->api)("audits/{$line['id']}", $this->w->c2))->assertNotFound()->assertJsonPath('code', 'audit_not_found');
    $other = orgToken(createMember($this->w->c3), $this->w->c3);
    $this->asToken($other)->getJson(($this->api)("audits/{$line['id']}", $this->w->c3))->assertNotFound();
    $this->asToken($other)->getJson(($this->api)('register'))->assertNotFound();

    // No permission, no register.
    $nobody = orgToken(staffWithRoles($this->w->g1, makeRole($this->w->g1, ['hrm.view'], 'Other')), $this->w->g1);
    $this->asToken($nobody)->getJson(($this->api)('register'))->assertForbidden();

    toggles()->disable($this->w->g1, 'compliance', 'Test');
    $this->asToken($this->gm)->getJson(($this->api)('register'))->assertForbidden();
});
