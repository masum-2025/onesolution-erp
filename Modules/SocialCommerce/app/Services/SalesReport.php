<?php

namespace Modules\SocialCommerce\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\OnlineOrders\Services\Orders;
use Modules\SocialCommerce\Models\Message;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Models\Thread;

/**
 * SC-5c: what Facebook brought a company between two of its days (its own
 * timezone): conversations, who answered them, and the orders placed from
 * them (through Online orders' public service, never its tables). Counted
 * in PHP, so it runs the same on every database. Amounts are the company's
 * currency; an order in another currency is counted but not added up.
 */
class SalesReport
{
    public const MAX_DAYS = 366;

    public const TOP_ITEMS = 10;

    private const CLOSED_BADLY = ['cancelled', 'returned'];

    public function __construct(private SocialBook $book, private ModuleResolver $modules) {}

    /**
     * @return array<string, mixed>
     */
    public function sales(Organization $company, string $from, string $to, ?string $pageId = null): array
    {
        $zone = $this->book->timezone($company);
        $currency = $this->book->currency($company);
        $start = CarbonImmutable::parse($from, $zone)->startOfDay();
        $end = CarbonImmutable::parse($to, $zone)->addDay()->startOfDay();
        [$startUtc, $endUtc] = [$start->utc(), $end->utc()];

        $pages = $this->book->query(SocialPage::class, $company)->orderBy('name')->get(['id', 'name'])->pluck('name', 'id');
        $byPage = [];
        foreach ($pages as $id => $name) {
            if ($pageId === null || $pageId === $id) {
                $byPage[$id] = ['page_id' => $id, 'name' => $name, 'conversations' => 0, 'orders' => 0, 'amount' => 0, 'delivered' => 0];
            }
        }
        $days = [];
        for ($day = $start; $day < $end; $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'conversations' => 0, 'orders' => 0, 'amount' => 0];
        }

        // Conversations started in the period, and those the customer wrote in (active).
        $newThreads = $this->book->query(Thread::class, $company)->when($pageId !== null, fn ($query) => $query->where('page_id', $pageId))
            ->where('created_at', '>=', $startUtc)->where('created_at', '<', $endUtc)->get(['id', 'kind', 'created_at']);
        $conversations = ['new' => $newThreads->count(), 'comments' => 0, 'messages' => 0, 'active' => 0, 'with_order' => 0];
        foreach ($newThreads as $thread) {
            $conversations[$thread->kind === Thread::COMMENT ? 'comments' : 'messages']++;
            $date = CarbonImmutable::parse($thread->created_at)->setTimezone($zone)->toDateString();
            if (isset($days[$date])) {
                $days[$date]['conversations']++;
            }
        }

        $messages = $this->book->query(Message::class, $company)->where('created_at', '>=', $startUtc)->where('created_at', '<', $endUtc);
        if ($pageId !== null) {
            $messages->whereIn('thread_id', $this->book->query(Thread::class, $company)->where('page_id', $pageId)->select('id'));
        }
        $answers = ['received' => 0, 'bot' => 0, 'person' => 0, 'by_keyword' => 0, 'by_ai' => 0, 'not_understood' => 0];
        $active = [];
        foreach ($messages->select(['thread_id', 'direction', 'sender', 'understood_by'])->cursor() as $message) {
            if ($message->direction === Message::IN) {
                $answers['received']++;
                $active[$message->thread_id] = true;
                match ($message->understood_by) {
                    'keyword' => $answers['by_keyword']++,
                    'ai' => $answers['by_ai']++,
                    default => $answers['not_understood']++,
                };
            } elseif ($message->sender === Message::BOT || $message->sender === Message::PERSON) {
                $answers[$message->sender]++;
            }
        }
        $conversations['active'] = count($active);
        $threadPages = $this->threadPages($company, array_keys($active));
        foreach (array_keys($active) as $threadId) {
            if (isset($byPage[$threadPages[$threadId] ?? ''])) {
                $byPage[$threadPages[$threadId]]['conversations']++;
            }
        }

        // Orders placed from Messenger in the period (none when Online orders is off).
        $ordersOn = class_exists(Orders::class) && $this->modules->isEnabled('online_orders', $company);
        $facts = $ordersOn ? app(Orders::class)->channelFacts($company, 'facebook', $startUtc, $endUtc) : [];
        $threadPages += $this->threadPages($company, array_values(array_diff(array_filter(array_column($facts, 'channel_ref')), array_keys($threadPages))));
        $orders = ['count' => 0, 'amount' => 0, 'average' => 0, 'delivered' => 0, 'delivered_amount' => 0, 'cancelled' => 0, 'open' => 0, 'paid_online' => 0, 'other_currency' => 0];
        $items = [];
        $ordered = [];
        foreach ($facts as $fact) {
            $page = $threadPages[$fact['channel_ref'] ?? ''] ?? null;
            if ($pageId !== null && $page !== $pageId) {
                continue;
            }
            $orders['count']++;
            $ours = $fact['currency'] === $currency;
            $amount = $ours ? $fact['total_minor'] : 0;
            $orders['other_currency'] += $ours ? 0 : 1;
            $orders['amount'] += $amount;
            $orders['paid_online'] += $ours ? $fact['paid_online_minor'] : 0;
            $bad = in_array($fact['status'], self::CLOSED_BADLY, true);
            if ($fact['status'] === 'delivered') {
                $orders['delivered']++;
                $orders['delivered_amount'] += $amount;
            } else {
                $orders[$bad ? 'cancelled' : 'open']++;
            }
            if ($fact['channel_ref'] !== null) {
                $ordered[$fact['channel_ref']] = true;
            }
            $date = $fact['placed_at']->setTimezone($zone)->toDateString();
            if (isset($days[$date])) {
                $days[$date]['orders']++;
                $days[$date]['amount'] += $amount;
            }
            if ($page !== null && isset($byPage[$page])) {
                $byPage[$page]['orders']++;
                $byPage[$page]['amount'] += $amount;
                $byPage[$page]['delivered'] += $fact['status'] === 'delivered' ? 1 : 0;
            }
            if (! $bad && $ours) {
                foreach ($fact['lines'] as $line) {
                    $items[$line['item_id']] ??= ['item_id' => $line['item_id'], 'name' => $line['name'], 'quantity_milli' => 0, 'amount' => 0, 'orders' => 0];
                    $items[$line['item_id']]['quantity_milli'] += $line['quantity_milli'];
                    $items[$line['item_id']]['amount'] += $line['total_minor'];
                    $items[$line['item_id']]['orders']++;
                }
            }
        }
        $countable = $orders['count'] - $orders['other_currency'];
        $orders['average'] = $countable > 0 ? intdiv($orders['amount'], $countable) : 0;
        $conversations['with_order'] = count(array_intersect_key($ordered, $active));
        usort($items, fn (array $a, array $b) => [$b['amount'], $b['quantity_milli']] <=> [$a['amount'], $a['quantity_milli']]);

        return [
            'from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(), 'timezone' => $zone, 'currency' => $currency,
            'page_id' => $pageId, 'orders_on' => $ordersOn,
            'conversations' => $conversations,
            // Basis points (1/100 of a percent), so no fractions travel: active conversations that ended in an order.
            'conversion_bp' => $conversations['active'] > 0 ? intdiv($conversations['with_order'] * 10000, $conversations['active']) : 0,
            'answers' => $answers,
            'orders' => $orders,
            'days' => array_values($days),
            'pages' => array_values($byPage),
            'items' => array_slice($items, 0, self::TOP_ITEMS),
        ];
    }

    /**
     * @param  list<string>  $threadIds
     * @return array<string, string> thread id => page id
     */
    private function threadPages(Organization $company, array $threadIds): array
    {
        $pages = [];
        foreach (array_chunk($threadIds, 500) as $ids) {
            $pages += $this->book->query(Thread::class, $company)->whereIn('id', $ids)->pluck('page_id', 'id')->all();
        }

        return $pages;
    }
}
