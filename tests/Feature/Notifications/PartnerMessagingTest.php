<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Notifications\Contracts\SmsGateway;
use App\Platform\Notifications\Models\NotificationTemplate;
use App\Platform\Notifications\Models\PartnerMailDomain;
use App\Platform\Notifications\Services\LogSmsGateway;
use App\Platform\Notifications\Services\SmsText;
use App\Platform\Partners\Contracts\DnsTxtLookup;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Support\Facades\Mail;

/*
 * Phase 5B-3b: the partner console for sending: own domain (DNS checks),
 * sender name, SMS sender ID, test messages, and message wording.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $this->ownerToken = partnerToken($this->owner, $this->w->partnerA);
    $this->gateway = new LogSmsGateway;
    app()->instance(SmsGateway::class, $this->gateway);
});

function addMailDomain(object $test, string $domain = 'mail.partner-a.test', ?string $token = null)
{
    return $test->asToken($token ?? $test->ownerToken)->postJson('http://localhost/api/partner/messaging/domain', ['domain' => $domain]);
}

// ── Sending domain ──

it('adds a sending domain with the DNS records to publish, never showing the private key', function () {
    $response = addMailDomain($this)->assertCreated()
        ->assertJsonPath('data.mail.domain.domain', 'mail.partner-a.test')
        ->assertJsonPath('data.mail.domain.status', 'pending')
        ->assertJsonPath('data.mail.domain.records.spf.value', 'v=spf1 include:'.config('notifications.mail.spf_include').' ~all')
        ->assertJsonPath('data.mail.from.own_domain', false);

    $domain = PartnerMailDomain::sole();
    expect($response->json('data.mail.domain.records.dkim.value'))->toBe("v=DKIM1; k=rsa; p={$domain->dkim_public_key}")
        ->and($response->getContent())->not->toContain('PRIVATE KEY')
        ->and($response->getContent())->not->toContain('verification_token')
        // Encrypted at rest.
        ->and(DB::table('partner_mail_domains')->value('dkim_private_key'))->not->toContain('PRIVATE KEY')
        ->and($domain->dkim_private_key)->toContain('PRIVATE KEY')
        ->and(AuditLog::where('action', 'partner.mail_domain_added')->sole()->partner_id)->toBe($this->w->partnerA->id);
});

it('uses the domain only when every record checks out, and names the ones that do not', function () {
    // One DNS for the whole test (controllers are reused between requests); its answers change.
    $dns = new class implements DnsTxtLookup
    {
        public array $records = [];

        public function txt(string $name): array
        {
            return $this->records[$name] ?? [];
        }
    };
    app()->instance(DnsTxtLookup::class, $dns);

    addMailDomain($this);
    $domain = PartnerMailDomain::sole();
    $records = $domain->records();

    $dns->records = [$records['ownership']['name'] => [$records['ownership']['value']], $records['spf']['name'] => ['v=spf1 include:_spf.google.com ~all']];
    $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/messaging/domain/verify')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'verification_failed')
        ->assertJsonPath('data.mail.domain.checks', ['ownership' => true, 'spf' => false, 'dkim' => false, 'dmarc' => false])
        ->assertJsonPath('message', 'These DNS records are missing or wrong: spf, dkim, dmarc. Check them with your DNS provider and try again; changes can take up to an hour.');

    $dns->records = collect($records)->mapWithKeys(fn (array $record) => [$record['name'] => [$record['value']]])->all();
    $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/messaging/domain/verify')->assertOk()
        ->assertJsonPath('data.mail.domain.status', 'active')
        ->assertJsonPath('data.mail.from.address', 'no-reply@mail.partner-a.test')
        ->assertJsonPath('data.mail.from.own_domain', true);

    // A record removed later stops the domain being used.
    $dns->records = [];
    $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/messaging/domain/verify')->assertUnprocessable();
    expect($domain->fresh()->status)->toBe('pending');
});

it('lets the owner name the sender and the reply address', function () {
    addMailDomain($this);

    $this->asToken($this->ownerToken)->patchJson('http://localhost/api/partner/messaging/domain', ['local_part' => 'billing', 'from_name' => 'Acme School ERP', 'reply_to' => 'help@partner-a.test'])
        ->assertOk()->assertJsonPath('data.mail.domain.local_part', 'billing');

    $this->asToken($this->ownerToken)->patchJson('http://localhost/api/partner/messaging/domain', ['from_name' => 'Evil <admin@bank.test>'])
        ->assertUnprocessable()->assertJsonValidationErrors('from_name');
});

it('keeps sending domains to owners, one partner each', function () {
    $support = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Support), $this->w->partnerA);
    $ownerB = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);

    addMailDomain($this, token: $support)->assertForbidden();
    addMailDomain($this)->assertCreated();
    addMailDomain($this, 'mail.partner-a.test', $ownerB)->assertUnprocessable()->assertJsonPath('code', 'domain_exists');
    $this->asToken($ownerB)->getJson('http://localhost/api/partner/messaging')->assertOk()->assertJsonPath('data.mail.domain', null);
    $this->asToken($ownerB)->postJson('http://localhost/api/partner/messaging/domain/verify')->assertNotFound();
});

it('refuses an own domain where the platform does not allow it', function () {
    partnerRule($this->w->partnerA, 'mail.custom_domain_allowed', false);

    addMailDomain($this)->assertForbidden()->assertJsonPath('code', 'custom_domain_not_allowed');
});

it('validates sending domains', function (string $domain) {
    addMailDomain($this, $domain)->assertUnprocessable()->assertJsonValidationErrors('domain');
})->with(['https://mail.acme.test', 'no-reply@acme.test', 'localhost', '10.0.0.1']);

it('sends the owner a test email in the partner\'s brand', function () {
    $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/messaging/test-email')->assertOk();

    $sent = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    expect($sent->getTo()[0]->getAddress())->toBe($this->owner->email)
        ->and($sent->getSubject())->toBe('Test email from Partner A');
});

// ── SMS ──

it('holds a sender ID for approval where operators register them, and uses it once approved', function () {
    $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/messaging/sms-sender', ['sender_id' => 'ACME ERP'])
        ->assertCreated()->assertJsonPath('data.sms.own.status', 'pending')->assertJsonPath('data.sms.sender_id', config('notifications.sms.default_sender'));

    $this->artisan('sms:sender', ['action' => 'approve', 'partner' => $this->w->partnerA->slug, '--reason' => 'Registered with the operators'])->assertSuccessful();

    $this->asToken($this->ownerToken)->getJson('http://localhost/api/partner/messaging')->assertJsonPath('data.sms.sender_id', 'ACME ERP');
});

it('uses a sender ID at once where no approval is needed', function () {
    partnerRule($this->w->partnerA, 'sms.sender_id_requires_approval', false);

    $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/messaging/sms-sender', ['sender_id' => 'AcmeERP'])
        ->assertCreated()->assertJsonPath('data.sms.own.status', 'approved');
});

it('validates sender IDs', function (string $sender) {
    $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/messaging/sms-sender', ['sender_id' => $sender])
        ->assertUnprocessable()->assertJsonValidationErrors('sender_id');
})->with(['AB', 'TWELVE CHARS', '1234567', 'ACME<ERP>']);

it('sends a test SMS only where SMS is turned on', function () {
    $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/messaging/test-sms', ['phone' => '+8801712345645'])
        ->assertUnprocessable()->assertJsonPath('code', 'sms_disabled');

    partnerRule($this->w->partnerA, 'notifications.sms_enabled', true);
    $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/messaging/test-sms', ['phone' => '+8801712345645'])
        ->assertOk()->assertJsonPath('message', 'Test SMS sent to +88017******45 as '.config('notifications.sms.default_sender').'.');

    expect($this->gateway->sent)->toHaveCount(1)->and($this->gateway->sent[0]['text'])->toBe('Partner A: this is a test message.');

    $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/messaging/test-sms', ['phone' => '01712345645'])
        ->assertUnprocessable()->assertJsonValidationErrors('phone');
});

it('counts SMS parts the way operators do', function (string $text, int $parts, bool $unicode) {
    expect(SmsText::segments($text))->toBe($parts)->and(SmsText::isUnicode($text))->toBe($unicode);
})->with([
    [str_repeat('a', 160), 1, false],
    [str_repeat('a', 161), 2, false],
    [str_repeat('ক', 70), 1, true],
    [str_repeat('ক', 71), 2, true],
]);

// ── Wording ──

it('lists every message with the placeholders it may use and its default wording', function () {
    $this->asToken($this->ownerToken)->getJson('http://localhost/api/partner/templates')->assertOk()
        ->assertJsonCount(18, 'data')
        ->assertJsonPath('data.0.key', 'support.requested')
        ->assertJsonPath('data.0.channels', ['mail', 'sms']);

    $this->getJson('http://localhost/api/partner/templates/support.requested')->assertOk()
        ->assertJsonPath('data.placeholders.0.name', 'product')
        ->assertJsonPath('data.wording.mail.en.custom', false)
        ->assertJsonPath('data.wording.mail.bn.subject', '{{ partner }} {{ organization }}-এর ভেতরে দেখতে চায়');
});

it('saves wording that uses only the offered placeholders, and resets it', function () {
    $url = 'http://localhost/api/partner/templates/exports.ready/mail/en';

    $this->asToken($this->ownerToken)->putJson($url, ['subject' => 'Ready for {{ organization }}', 'body' => 'Hello {{ owner_name }}'])
        ->assertUnprocessable()->assertJsonPath('code', 'unknown_placeholders')
        ->assertJsonPath('message', 'This message cannot use {{ owner_name }}. Use only the placeholders listed.');

    $this->asToken($this->ownerToken)->putJson($url, ['subject' => 'Ready for {{ organization }}', 'body' => "Your file:\n{{ link }}"])
        ->assertOk()->assertJsonPath('data.version', 1);
    expect(NotificationTemplate::sole()->subject)->toBe('Ready for {{ organization }}');

    $this->asToken($this->ownerToken)->deleteJson($url)->assertOk()->assertJsonPath('data.subject', 'Your data export from {{ organization }} is ready');
    expect(NotificationTemplate::count())->toBe(0)
        ->and(AuditLog::where('action', 'partner.template_reset')->exists())->toBeTrue();
});

it('refuses channels a message does not have and SMS wording with a subject', function () {
    $this->asToken($this->ownerToken)->putJson('http://localhost/api/partner/templates/exports.ready/sms/en', ['body' => 'Ready'])->assertNotFound();
    $this->asToken($this->ownerToken)->putJson('http://localhost/api/partner/templates/support.requested/sms/xx', ['body' => 'Hi there'])->assertNotFound();
    $this->asToken($this->ownerToken)->putJson('http://localhost/api/partner/templates/support.requested/sms/en', ['subject' => 'No', 'body' => 'Hi there'])
        ->assertUnprocessable()->assertJsonValidationErrors('subject');
});

it('previews wording in the partner\'s brand with example values', function () {
    $mail = $this->asToken($this->ownerToken)->postJson('http://localhost/api/partner/templates/billing.invoice_issued/mail/en/preview', [
        'subject' => 'Invoice {{ number }}',
        'body' => "Hello <b>{{ organization }}</b>,\n\nyou owe {{ amount }}.",
    ])->assertOk()
        ->assertJsonPath('data.subject', 'Invoice INV-2026-000123')
        ->assertJsonPath('data.from', 'Partner A');
    expect($mail->json('data.html'))->toContain('Hello &lt;b&gt;Sunrise School&lt;/b&gt;,')->toContain('you owe BDT 7,000.00.');

    // The editor frames it from its own page, which allows styles but no scripts, for its author only.
    $page = $this->actingAs($this->owner, 'web')->get($mail->json('data.preview_url'))->assertOk();
    expect($page->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->toContain("style-src 'unsafe-inline'")->not->toContain('script-src')
        ->and($page->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($page->getContent())->toContain('Hello &lt;b&gt;Sunrise School&lt;/b&gt;,');
    $this->actingAs(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), 'web')->get($mail->json('data.preview_url'))->assertNotFound();

    $this->postJson('http://localhost/api/partner/templates/billing.invoice_issued/sms/bn/preview', ['body' => '{{ product }}: ইনভয়েস {{ number }}'])->assertOk()
        ->assertJsonPath('data.text', 'Partner A: ইনভয়েস INV-2026-000123')
        ->assertJsonPath('data.unicode', true)
        ->assertJsonPath('data.segments', 1);
});

it('lets only owners change wording', function () {
    $support = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Support), $this->w->partnerA);

    $this->asToken($support)->getJson('http://localhost/api/partner/templates')->assertOk()->assertJsonPath('can_edit', false);
    $this->asToken($support)->putJson('http://localhost/api/partner/templates/exports.ready/mail/en', ['subject' => 'Hello there', 'body' => 'Ready now'])
        ->assertForbidden();
});
