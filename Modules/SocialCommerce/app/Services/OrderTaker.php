<?php

namespace Modules\SocialCommerce\Services;

use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Services\Stock;
use Modules\OnlineOrders\Services\Orders;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Models\Thread;

/**
 * Taking an order in Messenger (SC-3), one question at a time: the product
 * (known from the post or named, else chosen by number), how many (no more
 * than can go out of the page's warehouse), the name, the mobile number
 * (read by the company's country), the address and the delivery area (Online
 * orders' zones); then the summary with the real total (Orders::quote) and
 * "yes" places a draft order (cash on delivery) in Online orders, where
 * staff call and confirm it. "Cancel" or silence past
 * social_commerce.draft_hours drops it. Every line said is the company's own
 * (Automatic replies).
 *
 * Returns what to answer: ['text', 'intent', 'locale', 'items'], with 'wait'
 * when a person should take over; null when the message is not about an order.
 */
class OrderTaker
{
    public function __construct(
        private SocialBook $book,
        private Replies $replies,
        private Stock $stock,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** Whether this page takes orders in Messenger now. */
    public function takesOrders(Organization $company, SocialPage $page): bool
    {
        return class_exists(Orders::class) && $page->order_branch_id !== null && $page->order_warehouse_id !== null
            && $this->modules->isEnabled('online_orders', $company)
            && $this->rules->get('social_commerce.messenger_orders', $this->contexts->forOrganization($company)) === true;
    }

    /**
     * @param  list<string>  $items  products known for the message (named, the post, the last talked about)
     * @param  array{intent: string, locale: string}|null  $understood
     * @return array{text: string, intent: string, locale: string, items: list<string>, wait?: bool}|null
     */
    public function handle(Organization $company, SocialPage $page, Thread $thread, string $text, array $items, ?array $understood): ?array
    {
        $draft = $thread->order_draft;
        $hours = (int) $this->rules->get('social_commerce.draft_hours', $this->contexts->forOrganization($company));
        if ($draft !== null && ($thread->draft_at === null || $thread->draft_at->lt(CarbonImmutable::now()->subHours($hours)))) {
            $this->keep($company, $thread, null);
            $draft = null;
        }

        if ($draft === null) {
            if (($understood['intent'] ?? null) !== 'order' || ! $this->takesOrders($company, $page)) {
                return null;
            }

            return $this->start($company, $page, $thread, $understood['locale'], $items);
        }

        $locale = (string) $draft['locale'];
        if ($this->replies->says($company, 'cancel', $text) !== null) {
            $this->keep($company, $thread, null);

            return $this->say($company, 'cancelled', $locale, [], $draft);
        }

        return match ($draft['step']) {
            'product' => $this->product($company, $page, $thread, $draft, $text),
            'quantity' => $this->quantity($company, $page, $thread, $draft, $text),
            'name' => $this->name($company, $thread, $draft, $text),
            'phone' => $this->phone($company, $thread, $draft, $text),
            'address' => $this->address($company, $page, $thread, $draft, $text),
            'zone' => $this->zone($company, $page, $thread, $draft, $text),
            default => $this->confirm($company, $page, $thread, $draft, $text),
        };
    }

    // ---------------------------------------------------------------- steps

    /**
     * @param  list<string>  $items
     */
    private function start(Organization $company, SocialPage $page, Thread $thread, string $locale, array $items): array
    {
        $draft = ['locale' => $locale, 'started' => CarbonImmutable::now()->getTimestamp(), 'step' => 'product', 'item_id' => null, 'choices' => []];
        $this->book->query(Thread::class, $company)->whereKey($thread->getKey())->update(['draft_at' => now()]);
        if (count($items) === 1) {
            $draft = [...$draft, 'item_id' => $items[0], 'step' => 'quantity'];
            $this->keep($company, $thread, $draft);

            return $this->askQuantity($company, $draft);
        }

        return $this->askProduct($company, $thread, $draft, $items);
    }

    private function product(Organization $company, SocialPage $page, Thread $thread, array $draft, string $text): array
    {
        $number = $this->number($text);
        $chosen = $number !== null ? ($draft['choices'][$number - 1] ?? null) : null;
        $named = $chosen === null ? $this->replies->itemsNamedIn($company, $text) : [];
        if ($chosen === null && count($named) === 1) {
            $chosen = $named[0];
        }
        if ($chosen === null) {
            return $this->askProduct($company, $thread, $draft, $named);
        }
        $draft = [...$draft, 'item_id' => $chosen, 'step' => 'quantity', 'choices' => []];
        $this->keep($company, $thread, $draft);

        return $this->askQuantity($company, $draft);
    }

    private function quantity(Organization $company, SocialPage $page, Thread $thread, array $draft, string $text): array
    {
        $max = (int) $this->rules->get('social_commerce.max_quantity', $this->contexts->forOrganization($company));
        $wanted = $this->number($text);
        if ($wanted === null || $wanted < 1 || $wanted > $max) {
            return $this->askQuantity($company, $draft);
        }
        $item = $this->item($company, (string) $draft['item_id']);
        if ($item !== null && $item['kind'] === 'stock') {
            $available = intdiv((int) (app(Orders::class)->available($company, (string) $page->order_warehouse_id, [$item['id']])[$item['id']] ?? 0), 1000);
            if ($wanted > $available) {
                return $this->say($company, 'short_stock', $draft['locale'], ['product' => $this->name_($item, $draft['locale']), 'available' => $this->digits($available, $draft['locale'])], $draft);
            }
        }
        $draft = [...$draft, 'quantity' => $wanted, 'step' => 'name'];
        $this->keep($company, $thread, $draft);

        return $this->say($company, 'ask_name', $draft['locale'], [], $draft);
    }

    private function name(Organization $company, Thread $thread, array $draft, string $text): array
    {
        $name = mb_substr(trim($text), 0, 150);
        if (mb_strlen($name) < 2) {
            return $this->say($company, 'ask_name', $draft['locale'], [], $draft);
        }
        $draft = [...$draft, 'name' => $name, 'step' => 'phone'];
        $this->keep($company, $thread, $draft);

        return $this->say($company, 'ask_phone', $draft['locale'], [], $draft);
    }

    private function phone(Organization $company, Thread $thread, array $draft, string $text): array
    {
        $phone = PhoneNumber::normalize($this->latin(trim($text)), $this->country($company));
        if ($phone === null) {
            return $this->say($company, 'bad_phone', $draft['locale'], [], $draft);
        }
        $draft = [...$draft, 'phone' => $phone, 'step' => 'address'];
        $this->keep($company, $thread, $draft);

        return $this->say($company, 'ask_address', $draft['locale'], [], $draft);
    }

    private function address(Organization $company, SocialPage $page, Thread $thread, array $draft, string $text): array
    {
        $address = mb_substr(trim($text), 0, 500);
        if (mb_strlen($address) < 8) {
            return $this->say($company, 'ask_address', $draft['locale'], [], $draft);
        }
        $zones = app(Orders::class)->zones($this->branch($page));
        $draft = [...$draft, 'address' => $address];
        if ($zones === []) {
            $draft = [...$draft, 'zone' => null, 'step' => 'confirm'];
            $this->keep($company, $thread, $draft);

            return $this->summary($company, $page, $thread, $draft);
        }
        $draft = [...$draft, 'step' => 'zone'];
        $this->keep($company, $thread, $draft);

        return $this->askZone($company, $page, $draft);
    }

    private function zone(Organization $company, SocialPage $page, Thread $thread, array $draft, string $text): array
    {
        $zones = array_values(app(Orders::class)->zones($this->branch($page)));
        $number = $this->number($text);
        $zone = $number !== null ? ($zones[$number - 1] ?? null) : null;
        if ($zone === null) {
            return $this->askZone($company, $page, $draft);
        }
        $draft = [...$draft, 'zone' => $zone['code'], 'step' => 'confirm'];
        $this->keep($company, $thread, $draft);

        return $this->summary($company, $page, $thread, $draft);
    }

    private function confirm(Organization $company, SocialPage $page, Thread $thread, array $draft, string $text): array
    {
        if ($this->replies->says($company, 'confirm', $text) === null) {
            return $this->summary($company, $page, $thread, $draft);
        }
        try {
            $order = app(Orders::class)->place($company, $this->branch($page), [
                ...$this->orderData($page, $draft),
                'op_id' => 'fb-'.$thread->getKey().'-'.$draft['started'],
                'customer_name' => $draft['name'],
                'customer_phone' => $draft['phone'],
                'address' => $draft['address'],
            ], null, 'facebook', $thread->getKey());
        } catch (TenancyException|ValidationException) {
            return $this->handoff($company, $thread, $draft);
        }
        $this->keep($company, $thread, null);
        $this->book->query(Thread::class, $company)->whereKey($thread->getKey())->update(['last_order_id' => $order->getKey()]);
        $this->audit->record(action: 'social_commerce.order_taken', target: $thread, new: ['order' => $order->number, 'total_minor' => $order->total_minor], organizationId: $company->getKey());

        return $this->say($company, 'placed', $draft['locale'], ['number' => $order->number, 'total' => MoneyText::shown($order->total_minor, $order->currency_code, $draft['locale'])], $draft);
    }

    // ---------------------------------------------------------------- lines

    /**
     * @param  list<string>  $known
     */
    private function askProduct(Organization $company, Thread $thread, array $draft, array $known): array
    {
        $choices = $known !== [] ? $this->stock->items($company, $known) : array_values(array_filter($this->stock->items($company, null, null, 50), fn (array $item) => $item['sale_price_minor'] !== null));
        $choices = array_slice($choices, 0, 9);
        $currency = $this->book->currency($company);
        $lines = [];
        foreach ($choices as $index => $item) {
            $lines[] = ($index + 1).'. '.$this->name_($item, $draft['locale']).($item['sale_price_minor'] !== null && $currency !== '' ? ' — '.MoneyText::shown((int) $item['sale_price_minor'], $currency, $draft['locale']) : '');
        }
        $draft = [...$draft, 'step' => 'product', 'choices' => array_column($choices, 'id')];
        $this->keep($company, $thread, $draft);

        return $this->say($company, 'ask_product', $draft['locale'], ['products' => implode("\n", $lines)], $draft);
    }

    private function askQuantity(Organization $company, array $draft): array
    {
        $item = $this->item($company, (string) $draft['item_id']);

        return $this->say($company, 'ask_quantity', $draft['locale'], [
            'product' => $item === null ? '' : $this->name_($item, $draft['locale']),
            'max' => $this->digits((int) $this->rules->get('social_commerce.max_quantity', $this->contexts->forOrganization($company)), $draft['locale']),
        ], $draft);
    }

    private function askZone(Organization $company, SocialPage $page, array $draft): array
    {
        $currency = $this->book->currency($company);
        $lines = [];
        foreach (array_values(app(Orders::class)->zones($this->branch($page))) as $index => $zone) {
            $lines[] = ($index + 1).'. '.($zone['name'][$draft['locale']] ?? $zone['name']['en'] ?? $zone['code']).' — '.MoneyText::shown((int) $zone['charge_minor'], $currency, $draft['locale']);
        }

        return $this->say($company, 'ask_zone', $draft['locale'], ['zones' => implode("\n", $lines)], $draft);
    }

    private function summary(Organization $company, SocialPage $page, Thread $thread, array $draft): array
    {
        try {
            $quote = app(Orders::class)->quote($company, $this->branch($page), $this->orderData($page, $draft));
        } catch (TenancyException|ValidationException) {
            return $this->handoff($company, $thread, $draft);
        }
        $locale = $draft['locale'];
        $item = $this->item($company, (string) $draft['item_id']);
        $zone = $draft['zone'] === null ? null : collect(app(Orders::class)->zones($this->branch($page)))->firstWhere('code', $draft['zone']);

        return $this->say($company, 'summary', $locale, [
            'product' => $item === null ? '' : $this->name_($item, $locale),
            'quantity' => $this->digits((int) $draft['quantity'], $locale),
            'zone' => $zone === null ? '—' : (string) ($zone['name'][$locale] ?? $zone['name']['en'] ?? $zone['code']),
            'delivery' => MoneyText::shown($quote['delivery_minor'], $quote['currency'], $locale),
            'total' => MoneyText::shown($quote['total_minor'], $quote['currency'], $locale),
            'name' => (string) $draft['name'],
            'phone' => (string) $draft['phone'],
            'address' => (string) $draft['address'],
        ], $draft);
    }

    private function handoff(Organization $company, Thread $thread, array $draft): array
    {
        $this->keep($company, $thread, null);

        return [...$this->say($company, 'handoff', $draft['locale'], [], $draft), 'wait' => true];
    }

    /**
     * @param  array<string, string>  $values
     * @return array{text: string, intent: string, locale: string, items: list<string>}
     */
    private function say(Organization $company, string $intent, string $locale, array $values, array $draft): array
    {
        return [
            'text' => $this->replies->fill($company, $intent, $locale, $values) ?? '',
            'intent' => $intent,
            'locale' => $locale,
            'items' => $draft['item_id'] !== null ? [(string) $draft['item_id']] : [],
        ];
    }

    // ---------------------------------------------------------------- inside

    /** @return array<string, mixed> */
    private function orderData(SocialPage $page, array $draft): array
    {
        return [
            'warehouse_id' => $page->order_warehouse_id,
            'payment_method' => 'cod',
            'delivery_zone' => $draft['zone'] ?? null,
            'delivery_minor' => 0,
            'lines' => [['item_id' => $draft['item_id'], 'quantity_milli' => (int) $draft['quantity'] * 1000]],
        ];
    }

    private function keep(Organization $company, Thread $thread, ?array $draft): void
    {
        $this->book->query(Thread::class, $company)->whereKey($thread->getKey())->update([
            'order_draft' => $draft === null ? null : json_encode($draft, JSON_UNESCAPED_UNICODE),
            ...($draft === null ? ['draft_at' => null] : []),
            'updated_at' => now(),
        ]);
        $thread->order_draft = $draft;
    }

    private function branch(SocialPage $page): Organization
    {
        return Organization::query()->findOrFail($page->order_branch_id);
    }

    /** @return array<string, mixed>|null */
    private function item(Organization $company, string $id): ?array
    {
        return $this->stock->items($company, [$id])[0] ?? null;
    }

    private function name_(array $item, string $locale): string
    {
        return (string) (($item['names'][$locale] ?? null) ?: $item['name']);
    }

    /** The first whole number in a text (Bangla digits too). */
    private function number(string $text): ?int
    {
        return preg_match('/\d+/', $this->latin($text), $match) === 1 ? (int) $match[0] : null;
    }

    private function latin(string $text): string
    {
        return strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }

    /** A whole number in the language's own digits (৫ in Bangla). */
    private function digits(int $number, string $locale): string
    {
        return (string) ((new \NumberFormatter($locale, \NumberFormatter::DECIMAL))->format($number) ?: $number);
    }

    private function country(Organization $company): ?string
    {
        return app(OrganizationSettingsResolver::class)->values($company)['country_code'] ?? null;
    }
}
