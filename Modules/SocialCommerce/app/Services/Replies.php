<?php

namespace Modules\SocialCommerce\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Inventory\Services\Stock;
use Modules\OnlineOrders\Services\Orders;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;
use Modules\SocialCommerce\Models\ReplyTemplate;
use Modules\SocialCommerce\Models\SocialPage;

/**
 * What the bot understands and answers (SC-2, words only; SC-4 adds AI).
 *
 * A company's templates say which words mean which question (per language:
 * Banglish counts as Bangla) and what to answer; the first time they are
 * needed they are copied from database/data/reply-templates.php. Answers
 * are filled with the truth of the moment: the item's selling price and
 * stock from Inventory, delivery charges from Online orders' zones when
 * that module is on, the page's name.
 */
class Replies
{
    public function __construct(
        private SocialBook $book,
        private Stock $stock,
        private ModuleResolver $modules,
        private AuditLogger $audit,
    ) {}

    /**
     * The company's templates, by intent (made from the defaults when missing).
     *
     * @return Collection<string, ReplyTemplate>
     */
    public function templates(Organization $company): Collection
    {
        $have = $this->book->query(ReplyTemplate::class, $company)->get()->keyBy('intent');
        $defaults = require dirname(__DIR__, 2).'/database/data/reply-templates.php';
        foreach (ReplyTemplate::INTENTS as $intent) {
            if (! $have->has($intent)) {
                $have[$intent] = $this->book->query(ReplyTemplate::class, $company)->create([
                    'organization_id' => $company->getKey(), 'intent' => $intent, 'active' => true, 'version' => 1,
                    'keywords' => $defaults[$intent]['keywords'], 'answers' => $defaults[$intent]['answers'],
                ]);
            }
        }

        return collect(ReplyTemplate::INTENTS)->mapWithKeys(fn (string $intent) => [$intent => $have[$intent]]);
    }

    /**
     * The question in a text, and the language to answer in, or null.
     *
     * @return array{intent: string, locale: string}|null
     */
    public function understand(Organization $company, string $text): ?array
    {
        $normal = $this->normal($text);
        $tokens = array_values(array_filter(preg_split('/[^\p{L}\p{N}\p{M}]+/u', $normal) ?: []));
        $templates = $this->templates($company);
        // The longest matching words win ("delivery charge koto" is about delivery, "koto" alone about price);
        // on equal length, the earlier question.
        $best = null;
        $bestLength = 0;
        foreach (ReplyTemplate::DETECTED as $intent) {
            $template = $templates[$intent];
            if (! $template->active) {
                continue;
            }
            foreach ((array) $template->keywords as $locale => $words) {
                foreach ((array) $words as $word) {
                    $word = $this->bare((string) $word);
                    if ($word === '' || mb_strlen($word) <= $bestLength) {
                        continue;
                    }
                    $hit = preg_match('/[^\p{L}\p{N}\p{M}]/u', $word) === 1 ? str_contains($normal, $word) : in_array($word, $tokens, true);
                    if ($hit) {
                        [$best, $bestLength] = [['intent' => $intent, 'locale' => (string) $locale], mb_strlen($word)];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * The answer to a question in a language, with the facts of now; null when it cannot be
     * answered (no product known for a price, no delivery zones) — a person answers then.
     *
     * @param  list<string>  $itemIds
     */
    public function answer(Organization $company, SocialPage $page, string $intent, string $locale, array $itemIds): ?string
    {
        $template = $this->templates($company)[$intent] ?? null;
        if ($template === null || ! $template->active) {
            return null;
        }
        $text = (string) ($template->answers[$locale] ?? $template->answers['en'] ?? collect($template->answers)->first() ?? '');
        if (trim($text) === '') {
            return null;
        }
        $common = ['{page}' => $page->name];

        if (in_array($intent, ['price', 'stock'], true)) {
            $items = $itemIds === [] ? [] : $this->stock->items($company, $itemIds);
            if ($items === []) {
                return null;
            }
            $currency = $this->book->currency($company);
            $onHand = $this->stock->onHandAll($company, array_column(array_filter($items, fn (array $item) => $item['kind'] === 'stock'), 'id'));

            return implode("\n", array_map(function (array $item) use ($text, $common, $currency, $onHand, $locale) {
                $inStock = $item['kind'] !== 'stock' || ($onHand[$item['id']] ?? 0) > 0;

                return strtr($text, [
                    ...$common,
                    '{product}' => (string) (($item['names'][$locale] ?? null) ?: $item['name']),
                    '{price}' => $item['sale_price_minor'] === null || $currency === '' ? '—' : MoneyText::shown((int) $item['sale_price_minor'], $currency, $locale),
                    '{stock}' => __($inStock ? 'social_commerce::social.line.in_stock' : 'social_commerce::social.line.out_of_stock', [], $locale),
                ]);
            }, $items));
        }

        if ($intent === 'delivery') {
            $delivery = $this->delivery($company, $locale);
            if ($delivery === null) {
                return null;
            }
            $common['{delivery}'] = $delivery;
        }

        return trim(strtr($text, $common));
    }

    /**
     * Whether a short message says yes ("confirm") or stop ("cancel") while an order is taken;
     * the language of the word, or null. Only short messages count ("House no 4" is an address).
     */
    public function says(Organization $company, string $intent, string $text): ?string
    {
        $normal = $this->bare($text);
        $tokens = array_values(array_filter(preg_split('/[^\p{L}\p{N}\p{M}]+/u', $normal) ?: []));
        $template = $this->templates($company)[$intent] ?? null;
        if ($template === null || ! $template->active || $tokens === [] || count($tokens) > 3) {
            return null;
        }
        foreach ((array) $template->keywords as $locale => $words) {
            foreach ((array) $words as $word) {
                $word = $this->bare((string) $word);
                if ($word !== '' && ($word === $normal || in_array($word, $tokens, true))) {
                    return (string) $locale;
                }
            }
        }

        return null;
    }

    /**
     * A fixed answer (a question of taking an order, an order's news) in a language, filled; null when switched off or empty.
     *
     * @param  array<string, string>  $values  placeholder name => text
     */
    public function fill(Organization $company, string $intent, string $locale, array $values): ?string
    {
        $template = $this->templates($company)[$intent] ?? null;
        if ($template === null || ! $template->active) {
            return null;
        }
        $text = (string) ($template->answers[$locale] ?? $template->answers['en'] ?? '');
        if (trim($text) === '') {
            return null;
        }
        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs['{'.$key.'}'] = $value;
        }

        return trim(strtr($text, $pairs));
    }

    /**
     * Items whose name (in any language) is in the text — for a Messenger question with no post.
     *
     * @return list<string>
     */
    public function itemsNamedIn(Organization $company, string $text): array
    {
        $normal = $this->normal($text);
        $found = [];
        foreach ($this->stock->items($company, null, null, 500) as $item) {
            foreach (array_filter([$item['name'], ...array_values((array) $item['names'])]) as $name) {
                $name = $this->normal((string) $name);
                if (mb_strlen($name) >= 3 && str_contains($normal, $name)) {
                    $found[] = $item['id'];
                    break;
                }
            }
            if (count($found) >= 3) {
                break;
            }
        }

        return $found;
    }

    /**
     * Changes what the bot understands and answers for one question.
     *
     * @param  array<string, list<string>>  $keywords
     * @param  array<string, string>  $answers
     */
    public function change(Organization $company, string $intent, int $baseVersion, array $keywords, array $answers, bool $active, User $actor): ReplyTemplate
    {
        $template = $this->templates($company)[$intent] ?? throw SocialCommerceException::notFound('reply');

        return $this->book->transaction($company, function () use ($company, $template, $baseVersion, $keywords, $answers, $active, $actor) {
            $template = $this->book->query(ReplyTemplate::class, $company)->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            if ($template->version !== $baseVersion) {
                throw SocialCommerceException::versionConflict();
            }
            $clean = fn (array $words) => array_values(array_unique(array_filter(array_map(fn ($word) => mb_substr(trim((string) $word), 0, 60), $words), fn (string $word) => $word !== '')));
            $template->fill([
                'keywords' => in_array($template->intent, ReplyTemplate::FIXED, true) ? [] : array_map($clean, $keywords),
                'answers' => array_map(fn ($answer) => mb_substr(trim((string) $answer), 0, 1000), $answers),
                'active' => $active,
                'version' => $template->version + 1,
            ])->save();
            $this->audit->record(action: 'social_commerce.replies_changed', target: $template, new: ['intent' => $template->intent, 'active' => $active], actor: $actor, organizationId: $company->getKey());

            return $template;
        });
    }

    // ---------------------------------------------------------------- inside

    /** Delivery charges of the company's zones as one line, when Online orders is on and has zones. */
    private function delivery(Organization $company, string $locale): ?string
    {
        if (! class_exists(Orders::class) || ! $this->modules->isEnabled('online_orders', $company)) {
            return null;
        }
        $zones = app(Orders::class)->zones($company);
        $currency = $this->book->currency($company);
        if ($zones === [] || $currency === '') {
            return null;
        }

        return implode(', ', array_map(fn (array $zone) => ((string) ($zone['name'][$locale] ?? $zone['name']['en'] ?? $zone['code'])).' '.MoneyText::shown((int) $zone['charge_minor'], $currency, $locale), $zones));
    }

    private function normal(string $text): string
    {
        return mb_strtolower(Str::squish($text));
    }

    /** Lower case, single spaces, no question marks, stops or Bangla dari at the ends (by character, never by byte). */
    private function bare(string $text): string
    {
        return (string) preg_replace('/^[\s?!.।]+|[\s?!.।]+$/u', '', $this->normal($text));
    }
}
