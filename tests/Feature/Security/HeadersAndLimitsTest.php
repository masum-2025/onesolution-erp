<?php

use App\Platform\Security\SecurityLog;
use App\Platform\Tenancy\Enums\MembershipType;

/*
 * Phase 8-2: headers on every response (HSTS on HTTPS only) and the general
 * API limit per person, per organization and per address.
 */

function platformUrl(string $path, string $scheme = 'http'): string
{
    $url = parse_url(config('app.url'));

    return $scheme.'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '').$path;
}

it('sends HSTS on HTTPS responses, API and pages alike', function () {
    $this->getJson(platformUrl('/api/me', 'https'))
        ->assertUnauthorized()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    config(['security.hsts.include_subdomains' => true, 'security.hsts.preload' => true]);
    $this->get(platformUrl('/login', 'https'))
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload')
        ->assertHeader('Content-Security-Policy');
});

it('does not send HSTS over plain HTTP', function () {
    $this->getJson(platformUrl('/api/me'))
        ->assertUnauthorized()
        ->assertHeaderMissing('Strict-Transport-Security')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('limits API requests per person and logs it', function () {
    config(['security.api.per_user' => 3]);
    $log = Mockery::spy(SecurityLog::class);
    app()->instance(SecurityLog::class, $log);
    $w = tenancyWorld();
    $token = orgToken(createMember($w->c1), $w->c1);

    foreach (range(1, 3) as $request) {
        $this->asToken($token)->getJson('/api/menu')->assertOk();
    }

    $this->asToken($token)->getJson('/api/menu')->assertTooManyRequests();
    $log->shouldHaveReceived('record')->with('request.rate_limited', Mockery::any(), 'warning')->once();

    // Someone else in the same organization is not held back by that person.
    $this->asToken(orgToken(createMember($w->c1, MembershipType::Staff), $w->c1))->getJson('/api/menu')->assertOk();
});

it('limits API requests per organization across its people', function () {
    config(['security.api.per_organization' => 2]);
    $w = tenancyWorld();
    $first = orgToken(createMember($w->c1), $w->c1);
    $second = orgToken(createMember($w->c1, MembershipType::Staff), $w->c1);

    $this->asToken($first)->getJson('/api/menu')->assertOk();
    $this->asToken($second)->getJson('/api/menu')->assertOk();
    $this->asToken($second)->getJson('/api/menu')->assertTooManyRequests();

    // Another organization has its own allowance.
    $this->asToken(orgToken(createMember($w->c2), $w->c2))->getJson('/api/menu')->assertOk();
});

it('limits API requests per address, signed in or not', function () {
    config(['security.api.per_ip' => 2]);

    $this->getJson('/api/me')->assertUnauthorized();
    $this->getJson('/api/me')->assertUnauthorized();
    $this->getJson('/api/me')->assertTooManyRequests();
});

it('caps list pages at the configured maximum', function () {
    $w = tenancyWorld();
    $token = orgToken(createMember($w->c1), $w->c1);

    $this->asToken($token)->getJson('/api/organizations?per_page=100000')->assertOk()->assertJsonPath('meta.per_page', 100);
    $this->asToken($token)->getJson('/api/organizations?per_page=-5')->assertOk()->assertJsonPath('meta.per_page', 1);
});

it('refuses browser requests without the CSRF token (session sign-in and the API alike)', function () {
    // Laravel skips the CSRF check while running tests; this test turns it back on.
    app()['env'] = 'local';

    try {
        $w = tenancyWorld();
        $user = createMember($w->c1);

        $this->withHeader('Origin', config('app.url'))
            ->postJson('/session/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(419);

        // A first-party browser call to /api uses the session, so it needs the token too.
        $this->withHeader('Origin', config('app.url'))->withHeader('Referer', config('app.url').'/')
            ->postJson('/api/organizations', ['name' => ['en' => 'X']])
            ->assertStatus(419);

        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
    } finally {
        app()['env'] = 'testing';
    }
});
