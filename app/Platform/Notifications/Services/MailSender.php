<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Branding\BrandResolver;
use App\Platform\Notifications\Models\PartnerMailDomain;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;

/**
 * Sends one branded email. From the partner's own verified domain (signed
 * with its DKIM key) when it has one; otherwise from the platform address
 * with the partner's product name as the sender name. The body is escaped
 * text in the partner's brand; nothing a partner writes is rendered as HTML.
 */
class MailSender
{
    public function __construct(
        private BrandResolver $brands,
        private LinkBuilder $links,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @param  array{label: string, url: string}|null  $action
     * @return string The From address used.
     */
    public function send(?Partner $partner, string $to, string $subject, string $body, ?array $action, string $locale): string
    {
        $brand = $this->brands->for($partner);
        $sender = $this->sender($partner, $brand);
        ['html' => $html, 'text' => $text] = $this->render($partner, $body, $action, $locale, $brand);

        $email = (new Email)
            ->from(new Address($sender['address'], $sender['name']))
            ->to($to)
            ->subject($subject)
            ->text($text)
            ->html($html);

        if ($sender['reply_to'] !== null) {
            $email->replyTo($sender['reply_to']);
        }

        $message = $sender['dkim'] === null
            ? $email
            : (new DkimSigner($sender['dkim']['key'], $sender['dkim']['domain'], $sender['dkim']['selector']))->sign($email);

        Mail::mailer()->getSymfonyTransport()->send($message, Envelope::create($email));

        return $sender['address'];
    }

    /**
     * The message as it will look (also for previews), without sending it.
     *
     * @param  array{label: string, url: string}|null  $action
     * @param  array<string, mixed>|null  $brand
     * @return array{html: string, text: string}
     */
    public function render(?Partner $partner, string $body, ?array $action, string $locale, ?array $brand = null): array
    {
        $brand ??= $this->brands->for($partner);

        $html = view('mail.notification', [
            'locale' => $locale,
            'brand' => [
                'name' => $brand['name'],
                'color' => $brand['primary_color'],
                'logo' => $this->links->absolute($brand['logo_url'], $partner),
                'footer' => $brand['footer_text'][$locale] ?? $brand['footer_text']['en'] ?? null,
                'support_email' => $brand['support_email'],
                'powered_by' => $brand['powered_by'] ?? null,
            ],
            'paragraphs' => TemplateRenderer::paragraphs($body),
            'action' => $action,
        ])->render();

        $text = trim($body).($action === null ? '' : "\n\n{$action['label']}: {$action['url']}");

        return ['html' => $html, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $brand
     * @return array{address: string, name: string, reply_to: string|null, dkim: array{key: string, domain: string, selector: string}|null}
     */
    public function sender(?Partner $partner, ?array $brand = null): array
    {
        $brand ??= $this->brands->for($partner);
        $domain = $partner === null || $partner->is_house ? null : PartnerMailDomain::query()->where('partner_id', $partner->getKey())->first();
        $allowed = $partner !== null && (bool) $this->rules->get('mail.custom_domain_allowed', $this->contexts->forPartner($partner));

        if ($domain !== null && $domain->isActive() && $allowed) {
            return [
                'address' => $domain->fromAddress(),
                'name' => $domain->from_name ?? $brand['name'],
                'reply_to' => $domain->reply_to ?? $brand['support_email'],
                'dkim' => ['key' => $domain->dkim_private_key, 'domain' => $domain->domain, 'selector' => $domain->dkim_selector],
            ];
        }

        // Our address, their name: the client still sees the partner's product.
        return [
            'address' => (string) config('mail.from.address'),
            'name' => $partner === null || $partner->is_house ? (string) config('mail.from.name', $brand['name']) : $brand['name'],
            'reply_to' => $brand['support_email'],
            'dkim' => null,
        ];
    }
}
