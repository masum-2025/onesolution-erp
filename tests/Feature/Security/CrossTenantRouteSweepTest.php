<?php

use App\Platform\Audit\AuditLog;
use App\Platform\PartnerApi\Models\PartnerApiKey;
use App\Platform\Security\SecurityLog;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Phase 8-2: every endpoint that names an organization or a client in its
 * address is found automatically (new routes are covered without editing this
 * file) and called with another tenant's id, with every method. The answer is
 * always the same 404 as for an id that does not exist, before any validation
 * or controller runs, and nothing is written.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    // Many requests from one person: the general API limit is tested on its own.
    config(['security.api.per_user' => 100000, 'security.api.per_ip' => 100000, 'security.api.per_organization' => 100000]);
});

/**
 * Routes of a middleware group whose address names an organization.
 *
 * @return list<RouteDefinition>
 */
function tenantRoutes(string $middleware, array $parameters, string $prefix = 'api/'): array
{
    return array_values(array_filter(
        iterator_to_array(Route::getRoutes()),
        fn (RouteDefinition $route) => str_starts_with($route->uri(), $prefix)
            && in_array($middleware, $route->gatherMiddleware(), true)
            && array_intersect($parameters, $route->parameterNames()) !== [],
    ));
}

/**
 * The address with the target id for the named parameters and a fresh,
 * lower-case ULID (valid for every parameter pattern) for the others.
 */
function sweepUri(RouteDefinition $route, array $parameters, string $target): string
{
    return '/'.preg_replace_callback('/\{(\w+)\??\}/', fn (array $match) => in_array($match[1], $parameters, true)
        ? $target
        : Str::lower((string) Str::ulid()), $route->uri());
}

/**
 * @return list<string>
 */
function sweepMethods(RouteDefinition $route): array
{
    return array_values(array_diff($route->methods(), ['HEAD']));
}

/**
 * Call every route and method as the given token, returning "METHOD uri => status"
 * for every answer that is not the expected 404.
 */
function sweep(object $test, array $routes, array $parameters, string $target, string $token, string $code = 'organization_not_found'): array
{
    $unexpected = [];

    foreach ($routes as $route) {
        foreach (sweepMethods($route) as $method) {
            app('auth')->forgetGuards();
            app(CurrentContext::class)->clear();
            // Each endpoint's own limit runs before the tenant check (as it should); a
            // sweep of many endpoints would trip it, so its counters start fresh.
            Cache::flush();

            $response = $test->withToken($token)->json($method, sweepUri($route, $parameters, $target), []);

            if ($response->status() !== 404 || ($code !== '' && $response->json('code') !== $code)) {
                $unexpected[] = "{$method} {$route->uri()} => {$response->status()} ".$response->json('code');
            }
        }
    }

    return $unexpected;
}

it('answers 404 on every organization endpoint for another partner\'s company, before validation', function () {
    $routes = tenantRoutes('org', ['organization']);
    // A guard against a broken filter: the platform has dozens of such endpoints.
    expect(count($routes))->toBeGreaterThan(60);

    $attacker = createMember($this->w->c4);
    $token = orgToken($attacker, $this->w->c4);
    $audits = AuditLog::count();

    expect(sweep($this, $routes, ['organization'], $this->w->c1->id, $token))->toBe([])
        ->and(AuditLog::count())->toBe($audits);
});

it('answers 404 on every organization endpoint for a company of another group of the same partner', function () {
    $attacker = createMember($this->w->c3);

    expect(sweep($this, tenantRoutes('org', ['organization']), ['organization'], $this->w->c1->id, orgToken($attacker, $this->w->c3)))->toBe([]);
});

it('gives the same answer for another tenant\'s organization as for one that does not exist', function () {
    $token = orgToken(createMember($this->w->c4), $this->w->c4);

    expect(sweep($this, tenantRoutes('org', ['organization']), ['organization'], (string) Str::ulid(), $token))->toBe([]);
});

it('answers 404 on every partner console endpoint for another partner\'s client', function () {
    $routes = tenantRoutes('partner', ['organization', 'client']);
    expect(count($routes))->toBeGreaterThan(5);

    $token = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);
    $audits = AuditLog::count();

    expect(sweep($this, $routes, ['organization', 'client'], $this->w->c1->id, $token))->toBe([])
        ->and(AuditLog::count())->toBe($audits);
});

it('answers 404 on every partner API endpoint for another partner\'s client', function () {
    $routes = tenantRoutes('partner.key', ['client']);
    expect(count($routes))->toBeGreaterThan(2);

    $staff = createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner);
    $key = $this->asToken(partnerToken($staff, $this->w->partnerB))
        ->postJson('http://localhost/api/partner/api-keys', ['name' => 'Website', 'scopes' => PartnerApiKey::SCOPES])
        ->assertCreated()->json('data.key');
    $audits = AuditLog::count();

    // The partner API has its own not-found code; any 404 is the same answer as a missing client.
    expect(sweep($this, $routes, ['client'], $this->w->c1->id, $key, code: ''))->toBe([])
        ->and(AuditLog::count())->toBe($audits);
});

it('logs a cross-tenant attempt, but not a mistyped id', function () {
    $log = Mockery::spy(SecurityLog::class);
    app()->instance(SecurityLog::class, $log);
    $attacker = createMember($this->w->c4);
    $token = orgToken($attacker, $this->w->c4);

    $this->withToken($token)->getJson("/api/organizations/{$this->w->c1->id}")->assertNotFound();
    $this->withToken($token)->getJson('/api/organizations/'.Str::ulid())->assertNotFound();

    $log->shouldHaveReceived('record')->with('tenant.cross_access_attempt', Mockery::on(
        fn (array $context) => $context['requested_organization_id'] === $this->w->c1->id && $context['user_id'] === $attacker->id,
    ), 'warning')->once();
});

it('still lets people reach their own organization', function () {
    $owner = createMember($this->w->c1);

    $this->withToken(orgToken($owner, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}")->assertOk();
    $this->withToken(orgToken($owner, $this->w->c1))->getJson("/api/organizations/{$this->w->b1->id}")->assertOk();
});
