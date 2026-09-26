<?php

use App\Platform\Billing\Services\BillingRun;
use App\Platform\DataExport\Models\DataExport;
use App\Platform\DataExport\Services\DataExportService;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Models\NotificationTemplate;
use App\Platform\Notifications\Models\PartnerMailDomain;
use App\Platform\Notifications\Services\MailDomainService;
use App\Platform\SupportAccess\Enums\Severity;
use App\Platform\SupportAccess\Services\SupportAccessService;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;

/*
 * Phase 5B-3b: platform events reach the people who can act on them, by
 * email, in the partner's brand and the client's language; partner wording
 * replaces the default; nothing the partner writes is run or rendered as HTML.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->staff = createPartnerStaff($this->w->partnerA, PartnerUserRole::Support);
});

/** @return Collection<int, SentMessage> */
function sentMail(): Collection
{
    return Mail::mailer()->getSymfonyTransport()->messages();
}

function mailTo(string $email): ?SentMessage
{
    return sentMail()->first(fn (SentMessage $sent) => collect($sent->getEnvelope()->getRecipients())->contains(fn ($address) => $address->getAddress() === $email));
}

function askSupportFor(object $test, $organization, string $reason = 'Ticket #1432: fee report totals are wrong.')
{
    return app(SupportAccessService::class)->request($test->w->partnerA, $organization, $test->staff, $reason, Severity::High, 60);
}

it('tells the people who can approve support access, and nobody else', function () {
    $groupOwner = createMember($this->w->g1);
    $companyOwner = createMember($this->w->c1);
    $approver = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['support.approve'], 'IT lead'));
    $plainStaff = createMember($this->w->c1, MembershipType::Staff);
    $portal = createMember($this->w->c1, MembershipType::Portal);
    $otherCompanyOwner = createMember($this->w->c2);

    askSupportFor($this, $this->w->c1);

    expect(mailTo($groupOwner->email))->not->toBeNull()
        ->and(mailTo($companyOwner->email))->not->toBeNull()
        ->and(mailTo($approver->email))->not->toBeNull()
        ->and(mailTo($plainStaff->email))->toBeNull()
        ->and(mailTo($portal->email))->toBeNull()
        ->and(mailTo($otherCompanyOwner->email))->toBeNull();
});

it('writes in the partner\'s brand, from our address under the partner\'s name, with links to its address', function () {
    activeDomain($this->w->partnerA, 'erp.partner-a.test');
    $owner = createMember($this->w->c1);

    askSupportFor($this, $this->w->c1, 'Check <b>fees</b> & totals');

    $email = mailTo($owner->email)->getOriginalMessage();
    expect($email->getFrom()[0]->getName())->toBe('Partner A')
        ->and($email->getFrom()[0]->getAddress())->toBe(config('mail.from.address'))
        ->and($email->getSubject())->toBe('Partner A asks to look inside C1')
        ->and($email->getHtmlBody())->toContain('https://erp.partner-a.test/support-access')
        // The reason is the requester's text: shown, never rendered.
        ->and($email->getHtmlBody())->toContain('Check &lt;b&gt;fees&lt;/b&gt; &amp; totals')
        ->and($email->getHtmlBody())->not->toContain('<b>fees</b>')
        ->and($email->getTextBody())->toContain('Review the request: https://erp.partner-a.test/support-access');
});

it('writes in the client\'s language', function () {
    $this->w->g1->forceFill(['default_locale' => 'bn'])->save();
    $owner = createMember($this->w->c1);

    askSupportFor($this, $this->w->c1);

    expect(mailTo($owner->email)->getOriginalMessage()->getSubject())->toBe('Partner A C1-এর ভেতরে দেখতে চায়');
});

it('uses the partner\'s own wording, escaped, and keeps it to that partner', function () {
    NotificationTemplate::query()->forceCreate([
        'partner_id' => $this->w->partnerA->id,
        'notification_key' => 'support.requested',
        'channel' => 'mail',
        'locale' => 'en',
        'subject' => 'Heads up: {{ staff }} needs {{ minutes }} minutes',
        'body' => "Dear client,\n\n<script>alert(1)</script> {{ reason }}",
        'version' => 1,
    ]);
    $owner = createMember($this->w->c1);
    $otherPartnerOwner = createMember($this->w->c4);

    askSupportFor($this, $this->w->c1, 'Totals are wrong');

    $email = mailTo($owner->email)->getOriginalMessage();
    expect($email->getSubject())->toBe("Heads up: {$this->staff->name} needs 60 minutes")
        ->and($email->getHtmlBody())->toContain('&lt;script&gt;alert(1)&lt;/script&gt; Totals are wrong')
        ->and($email->getHtmlBody())->not->toContain('<script>');

    // Partner B's clients get the default wording.
    $staffB = createPartnerStaff($this->w->partnerB, PartnerUserRole::Support);
    app(SupportAccessService::class)->request($this->w->partnerB, $this->w->c4, $staffB, 'Something to check here', Severity::Low, 30);
    expect(mailTo($otherPartnerOwner->email)->getOriginalMessage()->getSubject())->toBe('Partner B asks to look inside C4');
});

it('sends from the partner\'s own domain, DKIM-signed, once every DNS record checks out', function () {
    $domain = app(MailDomainService::class)->add($this->w->partnerA, 'mail.partner-a.test', createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner));
    fakeDns(collect($domain->records())->mapWithKeys(fn (array $record) => [$record['name'] => [$record['value']]])->all());
    app(MailDomainService::class)->verify($domain);
    $owner = createMember($this->w->c1);

    askSupportFor($this, $this->w->c1);

    $sent = mailTo($owner->email);
    expect($sent->getEnvelope()->getSender()->getAddress())->toBe('no-reply@mail.partner-a.test')
        ->and($sent->toString())->toContain('DKIM-Signature: v=1; q=dns/txt; a=rsa-sha256;')
        ->and($sent->toString())->toContain('d=mail.partner-a.test;')
        ->and($sent->toString())->toContain('s='.$domain->dkim_selector.';');
});

it('falls back to our address while the domain is not verified or not allowed', function () {
    $domain = app(MailDomainService::class)->add($this->w->partnerA, 'mail.partner-a.test', createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner));
    $owner = createMember($this->w->g1);

    askSupportFor($this, $this->w->c1);
    expect(mailTo($owner->email)->getEnvelope()->getSender()->getAddress())->toBe(config('mail.from.address'));

    $domain->forceFill(['status' => PartnerMailDomain::ACTIVE])->save();
    partnerRule($this->w->partnerA, 'mail.custom_domain_allowed', false);
    Mail::mailer()->getSymfonyTransport()->flush();
    app(SupportAccessService::class)->request($this->w->partnerA, $this->w->c2, $this->staff, 'Another thing to check', Severity::Low, 30);

    expect(sentMail()->first()->getEnvelope()->getSender()->getAddress())->toBe(config('mail.from.address'));
});

it('records each message with a masked address and forgets the content once sent', function () {
    $owner = createMember($this->w->c1);
    $owner->forceFill(['email' => 'jane.doe@example.com'])->save();

    askSupportFor($this, $this->w->c1);

    $delivery = NotificationDelivery::where('user_id', $owner->id)->sole();
    expect($delivery->status)->toBe('sent')
        ->and($delivery->recipient)->toBe('j*******@example.com')
        ->and($delivery->data)->toBeNull()
        ->and($delivery->sender)->toBe(config('mail.from.address'))
        ->and($delivery->partner_id)->toBe($this->w->partnerA->id)
        ->and($delivery->organization_id)->toBe($this->w->c1->id);
});

it('tells the requester when the client decides', function () {
    $grant = askSupportFor($this, $this->w->c1);
    $owner = createMember($this->w->c1);

    app(SupportAccessService::class)->reject($grant, $owner, 'Not during exams, please');

    $email = mailTo($this->staff->email)->getOriginalMessage();
    expect($email->getSubject())->toBe('Support access to C1: rejected')
        ->and($email->getTextBody())->toContain('Not during exams, please');
});

it('tells the person who asked that the export is ready', function () {
    $owner = createMember($this->w->g1);
    $export = app(DataExportService::class)->request($this->w->g1, $owner);

    expect(DataExport::find($export->id)->status)->toBe('ready')
        ->and(mailTo($owner->email)->getOriginalMessage()->getSubject())->toBe('Your data export from G1 is ready');
});

it('sends invoices to clients who see billing and wholesale invoices to partner billing staff', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC'));
    $this->w->partnerB->forceFill(['billing_mode' => BillingMode::RevenueShare])->save();
    setPlan($this->w->g3, 'business');
    $clientOwner = createMember($this->w->g3);
    $partnerOwner = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $partnerBilling = createPartnerStaff($this->w->partnerA, PartnerUserRole::Billing);
    $partnerSales = createPartnerStaff($this->w->partnerA, PartnerUserRole::Sales);

    app(BillingRun::class)->run(CarbonImmutable::parse('2026-10-01', 'UTC'));

    $client = mailTo($clientOwner->email)->getOriginalMessage();
    expect($client->getSubject())->toBe('Invoice INV-2026-000002 from Partner B')
        ->and($client->getTextBody())->toContain('Amount: BDT 5,000.00')
        ->and(mailTo($partnerOwner->email)->getOriginalMessage()->getSubject())->toBe('Invoice INV-2026-000001 for your clients')
        ->and(mailTo($partnerBilling->email))->not->toBeNull()
        ->and(mailTo($partnerSales->email))->toBeNull();
});
