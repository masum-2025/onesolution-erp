<?php

use App\Platform\Security\SecurityLog;
use App\Platform\Tenancy\Enums\MembershipType;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;

/*
 * Phase 8-2: security events go to their own JSON log with ids and codes
 * only, never passwords, codes, tokens, emails or phone numbers.
 */

beforeEach(function () {
    $this->logFile = storage_path('logs/security-test-'.Str::random(8).'.log');
    $channel = 'security_test_'.Str::random(6);
    config([
        "logging.channels.{$channel}" => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'info', 'formatter' => JsonFormatter::class],
        'security.log_channel' => $channel,
    ]);
});

afterEach(function () {
    @unlink($this->logFile);
});

/**
 * @return list<array<string, mixed>>
 */
function securityEvents(string $file): array
{
    if (! is_file($file)) {
        return [];
    }

    return array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($file))));
}

it('logs a failed sign-in without the email or password', function () {
    $w = tenancyWorld();
    $user = createMember($w->c1);

    $this->withHeader('Origin', config('app.url'))
        ->postJson('/session/login', ['email' => $user->email, 'password' => 'wrong-password-123'])
        ->assertUnprocessable();

    $events = securityEvents($this->logFile);
    $failed = collect($events)->firstWhere('message', 'audit.auth.login_failed');

    expect($failed)->not->toBeNull()
        ->and($failed['context']['user_id'])->toBe($user->id)
        ->and($failed['context']['route'])->toBe('session/login')
        ->and($failed['level_name'])->toBe('WARNING')
        ->and(file_get_contents($this->logFile))->not->toContain($user->email)
        ->not->toContain('wrong-password-123');
});

it('logs refused requests with their error code', function () {
    $w = tenancyWorld();
    $staff = createMember($w->c1, MembershipType::Staff);

    // A staff member without roles may not change the organization.
    $this->asToken(orgToken($staff, $w->c1))->patchJson("/api/organizations/{$w->c1->id}", ['name' => ['en' => 'X']])->assertForbidden();

    $denied = collect(securityEvents($this->logFile))->firstWhere('message', 'access.denied');
    expect($denied['context']['user_id'])->toBe($staff->id)
        ->and($denied['context']['organization_id'])->toBe($w->c1->id);
});

it('logs rule and module changes, not everyday ones', function () {
    $w = tenancyWorld();

    toggles()->enable($w->c1, 'payroll', 'Test setup');
    orgRule($w->c1, 'attendance.late_grace_minutes', 10);

    $messages = collect(securityEvents($this->logFile))->pluck('message');
    expect($messages)->toContain('audit.rule.changed')
        ->and($messages->filter(fn (string $message) => str_starts_with($message, 'audit.module.')))->not->toBeEmpty()
        // Creating organizations is ordinary business, audited but not a security event.
        ->and($messages)->not->toContain('audit.organization.created');
});

it('drops secrets and personal data from the context, at any depth', function () {
    $clean = app(SecurityLog::class)->clean([
        'user_id' => 'U1',
        'api_key_id' => 'K1',
        'password' => 'x',
        'new_password' => 'x',
        'otp_code' => '123456',
        'email' => 'a@b.test',
        'details' => ['phone' => '+8801700000000', 'token' => 't', 'count' => 3],
        'model' => new stdClass,
    ]);

    expect($clean)->toBe([
        'user_id' => 'U1',
        'api_key_id' => 'K1',
        'details' => ['count' => 3],
        'model' => 'stdClass',
    ]);
});
