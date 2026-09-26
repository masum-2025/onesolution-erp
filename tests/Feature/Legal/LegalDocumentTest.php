<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Legal\Models\DocumentAcceptance;
use App\Platform\Legal\Models\LegalDocument;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\Mail;

/*
 * Phase 5B-4: clients accept the terms and the DPA in force (their
 * partner's, else the platform's); published versions never change; a new
 * version asks for acceptance again; nothing crosses partners.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->g1);
    $this->ownerToken = orgToken($this->owner, $this->w->g1);
    $this->partnerOwner = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
});

function publishTerms(object $test, string $summary = 'Clearer payment terms', ?string $token = null)
{
    return $test->asToken($token ?? $test->partnerOwner)->postJson('http://localhost/api/partner/legal/terms', [
        'title' => ['en' => 'Acme terms', 'bn' => 'Acme শর্তাবলি'],
        'body' => ['en' => "# Payment\nInvoices are due in 14 days.", 'bn' => "# পরিশোধ\n১৪ দিনের মধ্যে পরিশোধ।"],
        'summary' => $summary,
    ]);
}

it('binds clients to the platform\'s documents until the partner publishes its own', function () {
    $this->asToken($this->ownerToken)->getJson("http://localhost/api/organizations/{$this->w->g1->id}/provider")->assertOk()
        ->assertJsonPath('data.documents.0.kind', 'terms')
        ->assertJsonPath('data.documents.0.from_partner', false)
        ->assertJsonPath('data.pending', 2)
        ->assertJsonPath('data.can_accept', true);

    $this->getJson('/api/me')->assertJsonPath('data.context.account_owner', true)->assertJsonPath('data.context.legal_pending', 2);
});

it('records the owner\'s acceptance once, with who, when and which version', function () {
    $this->asToken($this->ownerToken)->getJson("http://localhost/api/organizations/{$this->w->g1->id}/legal/terms")->assertOk()
        ->assertJsonPath('data.title', 'Terms of service')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.accepted_at', null);

    $this->postJson("http://localhost/api/organizations/{$this->w->g1->id}/legal/terms/accept", ['version' => 1])->assertOk()
        ->assertJsonPath('data.pending', 1);
    $this->postJson("http://localhost/api/organizations/{$this->w->g1->id}/legal/terms/accept", ['version' => 1])->assertOk();

    $acceptance = DocumentAcceptance::sole();
    expect($acceptance->accepted_by)->toBe($this->owner->id)
        ->and($acceptance->version)->toBe(1)
        ->and(AuditLog::where('action', 'legal.accepted')->sole()->organization_id)->toBe($this->w->g1->id);
});

it('asks again when the partner publishes a new version, and tells the owners', function () {
    $this->asToken($this->ownerToken)->postJson("http://localhost/api/organizations/{$this->w->g1->id}/legal/terms/accept", ['version' => 1])->assertOk();
    Mail::mailer()->getSymfonyTransport()->flush();

    publishTerms($this)->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.platform', false);

    $this->asToken($this->ownerToken)->getJson("http://localhost/api/organizations/{$this->w->g1->id}/legal/terms")
        ->assertJsonPath('data.title', 'Acme terms')
        ->assertJsonPath('data.accepted_at', null);

    $sent = Mail::mailer()->getSymfonyTransport()->messages()->first(fn ($message) => $message->getEnvelope()->getRecipients()[0]->getAddress() === $this->owner->email);
    expect($sent->getOriginalMessage()->getSubject())->toBe('New version: Acme terms')
        ->and($sent->getOriginalMessage()->getTextBody())->toContain('What changed: Clearer payment terms');

    // Partner B's clients are not asked.
    $ownerB = createMember($this->w->g3);
    expect(Mail::mailer()->getSymfonyTransport()->messages()->contains(fn ($message) => $message->getEnvelope()->getRecipients()[0]->getAddress() === $ownerB->email))->toBeFalse();
});

it('refuses to accept an outdated version', function () {
    publishTerms($this);
    publishTerms($this, 'Second change');

    $this->asToken($this->ownerToken)->postJson("http://localhost/api/organizations/{$this->w->g1->id}/legal/terms/accept", ['version' => 1])
        ->assertStatus(409)->assertJsonPath('code', 'outdated')->assertJsonPath('current_version', 2);
});

it('lets only account owners accept', function () {
    $staff = createMember($this->w->g1, MembershipType::Staff);
    $companyOwner = createMember($this->w->c1);

    $this->asToken(orgToken($staff, $this->w->g1))->postJson("http://localhost/api/organizations/{$this->w->g1->id}/legal/terms/accept", ['version' => 1])
        ->assertForbidden()->assertJsonPath('code', 'owners_only');
    $this->asToken(orgToken($companyOwner, $this->w->c1))->postJson("http://localhost/api/organizations/{$this->w->c1->id}/legal/terms/accept", ['version' => 1])
        ->assertForbidden();
    // Anyone in the account may read it.
    $this->asToken(orgToken($staff, $this->w->g1))->getJson("http://localhost/api/organizations/{$this->w->g1->id}/legal/dpa")->assertOk()->assertJsonPath('data.can_accept', false);
});

it('never changes a published version and keeps partners apart', function () {
    publishTerms($this);
    publishTerms($this, 'Second change');

    expect(LegalDocument::where('partner_id', $this->w->partnerA->id)->where('kind', 'terms')->pluck('version')->sort()->values()->all())->toBe([1, 2])
        ->and(LegalDocument::where('partner_id', $this->w->partnerA->id)->where('version', 1)->value('summary'))->toBe('Clearer payment terms');

    $ownerB = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);
    $this->asToken($ownerB)->getJson('http://localhost/api/partner/legal')->assertOk()
        ->assertJsonPath('data.0.current.platform', true)
        ->assertJsonCount(0, 'data.0.own_versions');
    $this->asToken($ownerB)->getJson('http://localhost/api/partner/legal/terms/1')->assertNotFound();
});

it('validates new versions and lets only the owner publish', function () {
    $this->asToken($this->partnerOwner)->postJson('http://localhost/api/partner/legal/terms', ['title' => ['bn' => 'শর্ত'], 'body' => ['en' => 'Too short'], 'summary' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors(['title.en', 'body.en', 'summary']);
    $this->asToken($this->partnerOwner)->postJson('http://localhost/api/partner/legal/cookies', ['title' => ['en' => 'Cookies'], 'body' => ['en' => str_repeat('Cookie text. ', 3)], 'summary' => 'New policy'])
        ->assertNotFound();

    $sales = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Sales), $this->w->partnerA);
    publishTerms($this, token: $sales)->assertForbidden();
});

it('publishes the platform\'s documents from the data file only when there are none', function () {
    $this->artisan('legal:sync')->expectsOutputToContain('0 platform documents published.')->assertSuccessful();
});

it('lets One Solutions\' owners publish the platform default for every partner, and nobody else', function () {
    $house = Partner::factory()->house()->create(['name' => 'One Solutions']);
    $houseOwner = partnerToken(createPartnerStaff($house, PartnerUserRole::Owner), $house);
    $houseSales = partnerToken(createPartnerStaff($house, PartnerUserRole::Sales), $house);
    $body = [
        'title' => ['en' => 'Terms of service', 'bn' => 'সেবার শর্তাবলি'],
        'body' => ['en' => "# Scope\nReviewed by our lawyer for Bangladesh.", 'bn' => "# পরিধি\nবাংলাদেশের জন্য আইনজীবী যাচাই করেছেন।"],
        'summary' => 'Lawyer-reviewed text',
        'scope' => 'platform',
    ];

    $this->asToken($houseOwner)->getJson('http://localhost/api/partner/legal')->assertOk()
        ->assertJsonPath('can_publish_platform', true)
        ->assertJsonPath('data.0.platform.version', 1);
    $this->asToken($houseOwner)->getJson('http://localhost/api/partner/legal/terms/0?scope=platform')->assertOk()
        ->assertJsonPath('data.title_texts.en', 'Terms of service');

    $this->asToken($houseOwner)->postJson('http://localhost/api/partner/legal/terms', $body)->assertCreated()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.platform', true);

    // Partner A has no terms of its own: its clients are now bound by version 2.
    $this->asToken($this->ownerToken)->getJson("http://localhost/api/organizations/{$this->w->g1->id}/legal/terms")
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.body', "# Scope\nReviewed by our lawyer for Bangladesh.");

    // Another partner's owner, or house staff who are not owners, cannot.
    $this->asToken($this->partnerOwner)->postJson('http://localhost/api/partner/legal/terms', $body)->assertForbidden();
    $this->asToken($this->partnerOwner)->getJson('http://localhost/api/partner/legal/terms/0?scope=platform')->assertForbidden();
    $this->asToken($houseSales)->postJson('http://localhost/api/partner/legal/terms', $body)->assertForbidden();
    $this->asToken($this->partnerOwner)->getJson('http://localhost/api/partner/legal')->assertJsonPath('can_publish_platform', false)->assertJsonPath('data.0.platform', null);

    // A deploy does not overwrite what was published in the console; --force does.
    $this->artisan('legal:sync')->expectsOutputToContain('0 platform documents published.');
    expect(LegalDocument::whereNull('partner_id')->where('kind', 'terms')->max('version'))->toBe(2);
    $this->artisan('legal:sync', ['--force' => true])->expectsOutputToContain('1 platform documents published.');
});
