<?php

use App\Models\User;
use App\Platform\Access\Models\MembershipRole;
use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Modules\Hrm\Models\ImportRow;
use Modules\Hrm\Models\LoginDefaults;

/*
 * Employees' logins with the company's first password (no email needed):
 * kept as a hash, given one by one, many at once or by an import; the
 * employee must choose their own password before working, and the first one
 * runs out. Someone who has an account already keeps their own password.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC'));
    $this->w = hrmWorld($this);
    $this->hrm = "/api/organizations/{$this->w->c1->id}/hrm";
    $this->worker = makeRole($this->w->c1, ['attendance.punch'], 'Worker');
    $this->firstPassword = 'Factory-gate-2026';
    $this->setUp = fn (array $extra = []) => $this->asToken($this->w->token)->putJson("{$this->hrm}/login-settings", [
        'base_version' => 0, 'password' => $this->firstPassword, 'password_confirmation' => $this->firstPassword, 'role_ids' => [$this->worker->id], ...$extra,
    ]);
});

function firstLoginCsv(array $rows): UploadedFile
{
    $stream = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
        fputcsv($stream, $row, ',', '"', '');
    }
    rewind($stream);

    return UploadedFile::fake()->createWithContent('staff.csv', (string) stream_get_contents($stream));
}

it('keeps the first password as a hash only, with the roles new logins get', function () {
    $empty = $this->asToken($this->w->token)->getJson("{$this->hrm}/login-settings")->assertOk()->json('data');
    expect($empty)->toMatchArray(['password_set' => false, 'role_ids' => [], 'days' => 7, 'version' => 0])
        ->and(collect($empty['roles'])->pluck('id'))->toContain($this->worker->id);

    ($this->setUp)(['password' => 'short1', 'password_confirmation' => 'short1'])->assertUnprocessable()->assertJsonValidationErrors('password');
    ($this->setUp)(['role_ids' => [makeRole($this->w->c2, ['attendance.punch'], 'Elsewhere')->id]])->assertUnprocessable()->assertJsonValidationErrors('role_ids');
    ($this->setUp)(['surprise' => true])->assertUnprocessable();

    $saved = ($this->setUp)()->assertOk();
    expect($saved->json('data'))->toMatchArray(['password_set' => true, 'role_ids' => [$this->worker->id], 'version' => 1])
        ->and($saved->getContent())->not->toContain($this->firstPassword);
    $hash = LoginDefaults::inTenantOf($this->w->c1)->withoutGlobalScope(OrganizationScope::class)->sole()->password_hash;
    expect($hash)->not->toBe($this->firstPassword)->and(Hash::check($this->firstPassword, $hash))->toBeTrue();

    // A stale page changes nothing; leaving the password out keeps it.
    ($this->setUp)()->assertConflict()->assertJsonPath('code', 'version_conflict');
    $this->asToken($this->w->token)->putJson("{$this->hrm}/login-settings", ['base_version' => 1, 'role_ids' => []])->assertOk()->assertJsonPath('data.password_set', true);

    $audits = AuditLog::query()->where('action', 'hrm.login_defaults_changed')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->first()->new_values)->toBe(['role_ids' => [$this->worker->id], 'password_changed' => true])
        ->and(json_encode($audits->map->new_values))->not->toContain($this->firstPassword);

    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Viewer')), $this->w->c1);
    $this->asToken($viewer)->getJson("{$this->hrm}/login-settings")->assertForbidden();
    $hrOnly = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.manage'], 'HR')), $this->w->c1);
    $this->asToken($hrOnly)->getJson("{$this->hrm}/login-settings")->assertOk();
    $this->asToken($hrOnly)->putJson("{$this->hrm}/login-settings", ['base_version' => 2, 'role_ids' => []])->assertForbidden();
});

it('gives a login with the first password; the employee chooses their own before working', function () {
    $employee = hireVia($this, $this->w->token, $this->w->b1)->assertCreated()->json('data');
    $give = fn () => $this->asToken($this->w->token)->postJson("{$this->hrm}/employees/{$employee['id']}/first-login");

    $give()->assertUnprocessable()->assertJsonValidationErrors('login');
    ($this->setUp)()->assertOk();
    $given = $give()->assertOk();
    expect($given->json('meta.login'))->toBe('created')
        ->and($given->json('data.login_state'))->toBe('pending');
    $give()->assertUnprocessable()->assertJsonValidationErrors('login');

    $user = User::query()->where('phone', '+8801711000000')->sole();
    expect($user->must_change_password)->toBeTrue()
        ->and($user->phone_verified_at)->not->toBeNull()
        ->and($user->initial_password_expires_at->toDateString())->toBe('2026-10-22')
        ->and(AuditLog::query()->where('action', 'identity.initial_login_given')->sole()->new_values)->toMatchArray(['created' => true, 'phone_verified_by' => 'employer']);
    $membership = OrganizationMembership::query()->where('user_id', $user->id)->sole();
    expect($membership->organization_id)->toBe($this->w->b1->id)
        ->and(MembershipRole::query()->where('membership_id', $membership->id)->pluck('role_id')->all())->toBe([$this->worker->id]);

    // Signs in with the phone; no organization opens until they choose their own password.
    app(CurrentContext::class)->clear();
    $token = $this->postJson('/api/auth/login', ['phone' => '01711000000', 'country_code' => 'BD', 'password' => $this->firstPassword])->assertOk()->json('token');
    $this->asToken($token)->postJson('/api/auth/context', ['organization_id' => $this->w->b1->id])->assertForbidden()->assertJsonPath('code', 'password_change_required');
    $this->asToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.user.must_change_password', true);

    $change = fn (string $password) => $this->asToken($token)->putJson('/api/me/password', ['current_password' => $this->firstPassword, 'password' => $password, 'password_confirmation' => $password]);
    $change($this->firstPassword)->assertUnprocessable()->assertJsonValidationErrors('password');
    $change('My-own-secret-77')->assertOk();
    expect($user->fresh()->must_change_password)->toBeFalse()->and($user->fresh()->initial_password_expires_at)->toBeNull();

    app(CurrentContext::class)->clear();
    $own = $this->postJson('/api/auth/login', ['phone' => '01711000000', 'country_code' => 'BD', 'password' => 'My-own-secret-77'])->assertOk()->json('token');
    $this->asToken($own)->postJson('/api/auth/context', ['organization_id' => $this->w->b1->id])->assertOk();
    $this->asToken($this->w->token)->getJson("{$this->hrm}/employees/{$employee['id']}")->assertJsonPath('data.login_state', 'active');
    // Their own password now: HR cannot set it again.
    $this->asToken($this->w->token)->postJson("{$this->hrm}/employees/{$employee['id']}/first-login/reset")->assertUnprocessable()->assertJsonValidationErrors('login');
});

it('lets the first password run out, and HR gives it again', function () {
    ($this->setUp)()->assertOk();
    $employee = hireVia($this, $this->w->token, $this->w->b1, ['email' => 'rahima@factory.test'])->assertCreated()->json('data');
    $this->asToken($this->w->token)->postJson("{$this->hrm}/employees/{$employee['id']}/first-login")->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-10-23 06:00:00', 'UTC'));
    $this->postJson('/api/auth/login', ['email' => 'rahima@factory.test', 'password' => $this->firstPassword])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', __('tenancy.errors.initial_password_expired'));
    // HR signs in again too (tokens run out as well).
    $this->w->token = orgToken($this->w->owner, $this->w->c1);
    $list = $this->asToken($this->w->token)->getJson("{$this->hrm}/employees?login=without")->assertOk()->json('data');
    expect(collect($list)->pluck('id'))->not->toContain($employee['id']);
    $this->asToken($this->w->token)->getJson("{$this->hrm}/employees")->assertOk()->assertJsonPath('data.0.login_state', 'expired');

    $this->asToken($this->w->token)->postJson("{$this->hrm}/employees/{$employee['id']}/first-login/reset")->assertOk()->assertJsonPath('data.login_state', 'pending');
    $this->postJson('/api/auth/login', ['email' => 'rahima@factory.test', 'password' => $this->firstPassword])->assertOk();
    expect(AuditLog::query()->where('action', 'identity.initial_password_reset')->count())->toBe(1);
});

it('never touches the password of someone who has an account already', function () {
    ($this->setUp)()->assertOk();
    $existing = User::factory()->create(['email' => 'karim@example.com']);
    $employee = hireVia($this, $this->w->token, $this->w->b1, ['full_name' => 'Karim', 'email' => 'Karim@Example.com'])->assertCreated()->json('data');

    $this->asToken($this->w->token)->postJson("{$this->hrm}/employees/{$employee['id']}/first-login")->assertOk()->assertJsonPath('meta.login', 'linked');

    $fresh = $existing->fresh();
    expect(Hash::check('password', $fresh->password))->toBeTrue()
        ->and($fresh->must_change_password)->toBeFalse()
        ->and(OrganizationMembership::query()->where('user_id', $existing->id)->where('organization_id', $this->w->b1->id)->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->where('notification_key', 'members.added')->exists())->toBeTrue();
    $this->asToken($this->w->token)->postJson("{$this->hrm}/employees/{$employee['id']}/first-login/reset")->assertUnprocessable()->assertJsonValidationErrors('login');
});

it('gives many logins at once and says what happened to each', function () {
    ($this->setUp)()->assertOk();
    $one = hireVia($this, $this->w->token, $this->w->b1, ['phone' => '+8801711000001'])->json('data');
    $two = hireVia($this, $this->w->token, $this->w->d1, ['full_name' => 'Karim', 'phone' => '+8801711000002'])->json('data');
    $this->asToken($this->w->token)->postJson("{$this->hrm}/employees/{$two['id']}/first-login")->assertOk();
    $elsewhere = hireVia($this, orgToken(createMember($this->w->c2), $this->w->c2), $this->w->c2, ['phone' => '+8801711000003'])->json('data');

    $result = $this->asToken($this->w->token)->postJson("{$this->hrm}/employees/first-logins", ['employee_ids' => [$one['id'], $two['id'], $elsewhere['id']]])->assertOk();

    expect($result->json('meta'))->toBe(['created' => 1, 'linked' => 0, 'failed' => 2])
        ->and($result->json('data.0'))->toMatchArray(['id' => $one['id'], 'login' => 'created', 'error' => null])
        ->and($result->json('data.1.error'))->toBe(__('hrm::hrm.validation.login_already'))
        // Another company's employee is not even named.
        ->and($result->json('data.2'))->toMatchArray(['name' => null, 'login' => null])
        ->and(User::query()->where('phone', '+8801711000003')->exists())->toBeFalse();
    $this->asToken($this->w->token)->postJson("{$this->hrm}/employees/first-logins", ['employee_ids' => []])->assertUnprocessable();
});

it('imports employees with logins, reading roles by name and kinds of employment by label', function () {
    $file = firstLoginCsv([
        ['full_name', 'employment_type', 'joined_on', 'phone', 'role'],
        ['Rahima Akter', 'Permanent', '2026-10-01', '01711000011', 'worker'],
        ['Karim Uddin', 'স্থায়ী', '2026-10-01', '01711000012', ''],
        ['Salma Begum', 'Part time', '2026-10-01', '01711000013', 'Nobody'],
    ]);
    $checked = $this->asToken($this->w->token)->post("{$this->hrm}/imports", ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data');
    expect($checked['invalid_rows'])->toBe(1)
        ->and($checked['problems'][0])->toMatchArray(['row_no' => 4, 'errors' => ['role' => __('hrm::hrm.validation.import_role')]]);

    // No first password yet: the import with logins does not start.
    $this->asToken($this->w->token)->postJson("{$this->hrm}/imports/{$checked['id']}/start", ['skip_invalid' => true, 'create_logins' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('create_logins');
    ($this->setUp)(['role_ids' => []])->assertOk();

    $done = $this->asToken($this->w->token)->postJson("{$this->hrm}/imports/{$checked['id']}/start", ['skip_invalid' => true, 'create_logins' => true])->assertStatus(202)->json('data');
    expect($done)->toMatchArray(['status' => 'done', 'imported_rows' => 2, 'create_logins' => true, 'logins' => ['created' => 2, 'linked' => 0, 'failed' => 0]]);

    $rahima = User::query()->where('phone', '+8801711000011')->sole();
    $roles = MembershipRole::query()->whereIn('membership_id', OrganizationMembership::query()->where('user_id', $rahima->id)->pluck('id'))->pluck('role_id')->all();
    expect($rahima->must_change_password)->toBeTrue()
        ->and($roles)->toBe([$this->worker->id])
        ->and(User::query()->where('phone', '+8801711000012')->sole()->must_change_password)->toBeTrue()
        ->and(ImportRow::query()->where('import_id', $checked['id'])->where('login', 'created')->count())->toBe(2);
});

it('stays inside the company and needs HRM on', function () {
    ($this->setUp)()->assertOk();
    $employee = hireVia($this, $this->w->token, $this->w->b1)->json('data');

    $c2Owner = orgToken(createMember($this->w->c2), $this->w->c2);
    $this->asToken($c2Owner)->postJson("/api/organizations/{$this->w->c2->id}/hrm/employees/{$employee['id']}/first-login")->assertNotFound();
    $c4Owner = orgToken(createMember($this->w->c4), $this->w->c4);
    $this->asToken($c4Owner)->getJson("{$this->hrm}/login-settings")->assertNotFound();
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Viewer')), $this->w->c1);
    $this->asToken($viewer)->postJson("{$this->hrm}/employees/{$employee['id']}/first-login")->assertForbidden();

    toggles()->disable($this->w->g1, 'hrm', 'Test');
    $this->asToken($this->w->token)->postJson("{$this->hrm}/employees/{$employee['id']}/first-login")->assertForbidden();
    expect(User::query()->where('phone', '+8801711000000')->exists())->toBeFalse();
});
