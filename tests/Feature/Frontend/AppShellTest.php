<?php

/*
 * The page that boots the browser app: strict CSP with a per-request nonce,
 * no framing, brand data escaped, and API paths never swallowed by it.
 */

beforeEach(function () {
    // Built assets are not needed to test the page itself.
    $this->withoutVite();
});

it('serves the app shell for any page path', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="app"', false);
})->with(['/', '/login', '/rules', '/organizations/01ARZ3NDEKTSV4RRFFQ69G5FAV?tab=members']);

it('sends a nonce-based content security policy', function () {
    $response = $this->get('/login');

    $policy = $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $policy, $match);

    expect($policy)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('unsafe-inline')
        ->not->toContain('unsafe-eval')
        ->and($match[1] ?? null)->not->toBeNull()
        ->and($response->getContent())->toContain('nonce="'.$match[1].'"');

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('uses a new nonce on every request', function () {
    $first = $this->get('/login')->headers->get('Content-Security-Policy');
    $second = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

it('escapes brand data written into the page', function () {
    config(['branding.house.name' => '<script>alert(1)</script>', 'branding.house.primary_color' => 'red;}</style>']);

    $html = $this->get('/login')->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->not->toContain('red;}</style>')
        ->toContain('--brand:#2B4C9B');
});

it('keeps API and session paths out of the app shell', function () {
    $this->getJson('/api/does-not-exist')->assertNotFound()->assertHeaderMissing('Content-Security-Policy');
    $this->get('/session/login')->assertStatus(405);
});

it('holds no user data in the shell', function () {
    $user = createMember(tenancyWorld()->c1);
    spaSession($this, $user);

    expect($this->get('/')->getContent())->not->toContain($user->email);
});
