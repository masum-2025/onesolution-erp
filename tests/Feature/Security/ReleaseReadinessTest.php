<?php

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Security\Console\SecurityEndpoints;
use App\Platform\Security\EndpointInventory;
use App\Platform\Security\Findings;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Actions\CreateOrganization;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Database\Seeders\PentestWorldSeeder;
use Illuminate\Support\Facades\File;

/*
 * Phase 11: what an independent security test and a release rely on: the
 * endpoint inventory, the remediation tracker, the release gate and the
 * staging world for the testers.
 */

// Which endpoints may be public at all is reviewed in PublicRoutesTest (Phase 8-2).
it('keeps the endpoint inventory in step with the routes', function () {
    expect(File::get(base_path(SecurityEndpoints::FILE)))->toBe(app(EndpointInventory::class)->markdown());
});

it('keeps the remediation tracker valid', function () {
    expect(app(Findings::class)->problems())->toBe([]);
});

function trackerWith(array $findings): Findings
{
    $path = storage_path('framework/testing/findings-'.Str::random(6).'.json');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode(['findings' => $findings]));

    return new Findings($path);
}

it('requires a commit and an existing regression test for every fixed finding', function () {
    $tracker = trackerWith([
        ['id' => 'PT-2026-001', 'severity' => 'high', 'status' => 'fixed', 'title' => 'Tenant data reachable by id', 'fixed_in' => 'abc1234',
            'regression_test' => 'tests/Feature/Tenancy/TenantIsolationTest.php::it returns 404 for guessed ids'],
        ['id' => 'PT-2026-002', 'severity' => 'medium', 'status' => 'fixed', 'title' => 'Missing limit', 'fixed_in' => 'abc1234',
            'regression_test' => 'tests/Feature/Tenancy/TenantIsolationTest.php::it does not exist'],
        ['id' => 'PT-2026-003', 'severity' => 'low', 'status' => 'fixed', 'title' => 'No commit'],
        ['id' => 'PT-2026-004', 'severity' => 'low', 'status' => 'accepted', 'title' => 'Accepted without a reason'],
        ['id' => 'BAD', 'severity' => 'urgent', 'status' => 'done', 'title' => ''],
        ['id' => 'PT-2026-001', 'severity' => 'info', 'status' => 'open', 'title' => 'Twice'],
    ]);

    $problems = implode("\n", $tracker->problems());

    expect($problems)->not->toContain('PT-2026-001: regression')
        ->toContain('PT-2026-002: no test "it does not exist"')
        ->toContain('PT-2026-003: a fixed finding names the commit')
        ->toContain('PT-2026-003: regression_test must be')
        ->toContain('PT-2026-004: an accepted risk needs accepted_reason')
        ->toContain('BAD: the id must look like')
        ->toContain('BAD: severity must be')
        ->toContain('BAD: status must be')
        ->toContain('BAD: needs a short title')
        ->toContain('PT-2026-001: used twice');
});

it('blocks a release only for open critical and high findings', function () {
    $tracker = trackerWith([
        ['id' => 'PT-2026-001', 'severity' => 'critical', 'status' => 'open', 'title' => 'A'],
        ['id' => 'PT-2026-002', 'severity' => 'high', 'status' => 'open', 'title' => 'B'],
        ['id' => 'PT-2026-003', 'severity' => 'medium', 'status' => 'open', 'title' => 'C'],
        ['id' => 'PT-2026-004', 'severity' => 'high', 'status' => 'accepted', 'title' => 'D', 'accepted_reason' => 'Owner, 2026-10-01: compensating control'],
    ]);

    expect(array_column($tracker->blocking(), 'id'))->toBe(['PT-2026-001', 'PT-2026-002']);
});

it('refuses a release without a recent backup and restore drill, and passes once they are there', function () {
    $this->artisan('release:check')->expectsOutputToContain('do not release')->assertFailed();

    app(AuditLogger::class)->record('backup.created');
    app(AuditLogger::class)->record('backup.restore_drill_passed');

    $this->artisan('release:check')->expectsOutputToContain('All release checks passed')->assertSuccessful();
});

it('also checks the server settings for production', function () {
    app(AuditLogger::class)->record('backup.created');
    app(AuditLogger::class)->record('backup.restore_drill_passed');

    // The test server is not set up like production (debug, http address).
    $this->artisan('release:check', ['--production' => true])->expectsOutputToContain('Server: app_env')->assertFailed();
});

it('builds two separate partner worlds for the testers, never in production', function () {
    $this->seed(PentestWorldSeeder::class);

    $alpha = Partner::query()->where('slug', 'pentest-alpha')->sole();
    $bravo = Partner::query()->where('slug', 'pentest-bravo')->sole();
    $bravoCompany = Organization::query()->where('partner_id', $bravo->id)->where('type', 'company')->firstOrFail();
    $alphaOwner = User::query()->where('email', 'owner.alpha@pentest.test')->sole();

    expect(User::query()->where('email', 'like', '%@pentest.test')->count())->toBe(16)
        ->and(Organization::query()->where('partner_id', $alpha->id)->count())->toBe(4)
        ->and(fn () => app(ContextResolver::class)->enterOrganization($alphaOwner, $bravoCompany->id))->toThrow(OrganizationAccessDenied::class);

    // A second run changes nothing.
    $this->seed(PentestWorldSeeder::class);
    expect(User::query()->where('email', 'like', '%@pentest.test')->count())->toBe(16);

    app()['env'] = 'production';
    try {
        expect(fn () => app(PentestWorldSeeder::class)->run(app(CreateOrganization::class), app(AddMember::class)))
            ->toThrow(RuntimeException::class, 'staging only');
    } finally {
        app()['env'] = 'testing';
    }
});
