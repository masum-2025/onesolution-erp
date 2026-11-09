<?php

namespace Modules\SocialCommerce\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Meta\Exceptions\MetaException;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;
use Modules\SocialCommerce\Models\Message;
use Modules\SocialCommerce\Models\Post;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Models\Thread;
use Modules\SocialCommerce\Models\WebhookEvent;

/**
 * Comments and Messenger chats of a company's pages, in one inbox, and the
 * bot that answers them (public service of the module).
 *
 * A comment or message coming in (once per Meta id) joins its thread. The
 * bot answers when replies are on (rule social_commerce.auto_reply and the
 * page's switch), never to the page itself, never the same question again
 * within social_commerce.reply_cooldown_minutes. A price under a comment
 * goes public or, with the page's price switch off, to the customer's
 * inbox with a public notice. What it cannot answer counts; at
 * social_commerce.handoff_after_unknown it says a person will answer and
 * the thread waits for one (shown in the bell). People answer from the inbox.
 */
class Conversations
{
    public function __construct(
        private SocialBook $book,
        private Replies $replies,
        private Posts $posts,
        private Pages $pages,
        private Sender $sender,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private ModuleResolver $modules,
        private AuditLogger $audit,
    ) {}

    /**
     * A comment under one of the page's posts (Meta's feed change, item comment, verb add).
     *
     * @param  array<string, mixed>  $value
     */
    public function commentReceived(Organization $company, SocialPage $page, array $value): ?Message
    {
        $commentId = (string) ($value['comment_id'] ?? '');
        $from = (array) ($value['from'] ?? []);
        $customer = (string) ($from['id'] ?? '');
        $text = trim((string) ($value['message'] ?? ''));
        // The page's own comments (its replies) are not questions.
        if ($commentId === '' || $customer === '' || $customer === $page->page_id || $text === '' || ! $this->firstTime($company, "comment:{$commentId}")) {
            return null;
        }
        $fbPost = (string) ($value['post_id'] ?? '');
        $thread = $this->thread($company, $page, Thread::COMMENT, "c:{$fbPost}:{$customer}", $customer, (string) ($from['name'] ?? ''), $fbPost);
        $in = $this->incoming($company, $thread, Message::PUBLIC, $text, $commentId);
        $this->autoReply($company, $page, $thread, $in, $this->postItems($company, $fbPost), $commentId);

        return $in;
    }

    /**
     * A Messenger message to the page (Meta's messaging event, not an echo of the page's own).
     *
     * @param  array<string, mixed>  $event
     */
    public function messageReceived(Organization $company, SocialPage $page, array $event): ?Message
    {
        $message = (array) ($event['message'] ?? []);
        $mid = (string) ($message['mid'] ?? '');
        $customer = (string) ($event['sender']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        if ($mid === '' || $customer === '' || ($message['is_echo'] ?? false) || $customer === $page->page_id || $text === '' || ! $this->firstTime($company, "message:{$mid}")) {
            return null;
        }
        $thread = $this->thread($company, $page, Thread::MESSAGE, "m:{$customer}", $customer, '', null);
        $in = $this->incoming($company, $thread, Message::PRIVATE, $text, $mid);

        // Which product: one named in the text, else the post or ad they came from, else the last one talked about.
        $items = $this->replies->itemsNamedIn($company, $text);
        $fbPost = (string) ($event['referral']['ad_id'] ?? $event['referral']['post_id'] ?? $message['reply_to']['story']['id'] ?? '');
        if ($items === [] && $fbPost !== '') {
            $items = $this->postItems($company, $fbPost);
        }
        if ($items === []) {
            $items = (array) ($this->book->query(Message::class, $company)->where('thread_id', $thread->getKey())->whereNotNull('item_ids')
                ->orderByDesc('created_at')->orderByDesc('id')->value('item_ids') ?? []);
        }
        $this->autoReply($company, $page, $thread, $in, $items, null);

        return $in;
    }

    /** A person answers from the inbox: in Messenger, or under the customer's last comment. */
    public function reply(Organization $company, Thread $thread, string $text, ?string $opId, User $actor): Message
    {
        if ($opId !== null && ($done = $this->book->query(Message::class, $company)->where('op_id', $opId)->first()) !== null) {
            return $done;
        }
        $page = $this->book->query(SocialPage::class, $company)->find($thread->page_id) ?? throw SocialCommerceException::notFound('page');
        $to = $thread->kind === Thread::COMMENT ? $this->lastCommentId($company, $thread) : $thread->customer_ref;
        if ($to === null) {
            throw SocialCommerceException::cannotSend(__('social_commerce::social.failures.rejected'));
        }
        $out = $this->send($company, $page, $thread, $thread->kind === Thread::COMMENT ? Message::PUBLIC : Message::PRIVATE, $text, $to, Message::PERSON, $actor, null, null, [], $opId);
        if ($out->status !== 'sent') {
            throw SocialCommerceException::cannotSend(__(trans()->has("social_commerce::social.failures.{$out->error_code}") ? "social_commerce::social.failures.{$out->error_code}" : 'social_commerce::social.failures.rejected'));
        }
        $this->book->query(Thread::class, $company)->whereKey($thread->getKey())->update(['status' => Thread::OPEN, 'unknown_count' => 0, 'assigned_to' => $thread->assigned_to ?? $actor->getKey(), 'version' => $thread->version + 1, 'updated_at' => now()]);
        $this->audit->record(action: 'social_commerce.reply_sent', target: $thread, new: ['kind' => $thread->kind], actor: $actor, organizationId: $company->getKey());

        return $out;
    }

    /** claim (it is mine), done (finished), reopen. */
    public function mark(Organization $company, Thread $thread, int $baseVersion, string $step, User $actor): Thread
    {
        return $this->book->transaction($company, function () use ($company, $thread, $baseVersion, $step, $actor) {
            $thread = $this->book->query(Thread::class, $company)->whereKey($thread->getKey())->lockForUpdate()->firstOrFail();
            if ($thread->version !== $baseVersion) {
                throw SocialCommerceException::versionConflict();
            }
            $thread->fill(match ($step) {
                'claim' => ['assigned_to' => $actor->getKey()],
                'done' => ['status' => Thread::DONE, 'unknown_count' => 0],
                'reopen' => ['status' => Thread::OPEN],
            } + ['version' => $thread->version + 1])->save();
            $this->audit->record(action: "social_commerce.thread_{$step}", target: $thread, actor: $actor, organizationId: $company->getKey());

            return $thread;
        });
    }

    /** Threads waiting for a person at a company (the bell). */
    public function waiting(Organization $company): int
    {
        return $this->book->query(Thread::class, $company)->where('status', Thread::WAITING)->count();
    }

    /** Forgets conversations older than social_commerce.keep_messages_days and old webhook ids. Returns threads removed. */
    public function prune(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $removed = 0;
        $this->book->everyDatabase(function () use ($now, &$removed) {
            WebhookEvent::query()->withoutGlobalScope(OrganizationScope::class)->where('created_at', '<', $now->subDays(7))->delete();
            foreach (Thread::query()->withoutGlobalScope(OrganizationScope::class)->distinct()->pluck('organization_id') as $companyId) {
                $company = Organization::query()->find($companyId);
                if ($company === null) {
                    continue;
                }
                $days = (int) $this->rules->get('social_commerce.keep_messages_days', $this->contexts->forOrganization($company));
                $old = $this->book->query(Thread::class, $company)->where('updated_at', '<', $now->subDays($days))->pluck('id')->all();
                foreach (array_chunk($old, 500) as $chunk) {
                    $this->book->query(Message::class, $company)->whereIn('thread_id', $chunk)->delete();
                    $removed += $this->book->query(Thread::class, $company)->whereIn('id', $chunk)->delete();
                }
            }
        });

        return $removed;
    }

    // ---------------------------------------------------------------- inside

    /**
     * @param  list<string>  $items
     */
    private function autoReply(Organization $company, SocialPage $page, Thread $thread, Message $in, array $items, ?string $commentId): void
    {
        $context = $this->contexts->forOrganization($company);
        if ($this->rules->get('social_commerce.auto_reply', $context) !== true || ! $page->auto_reply || ! $this->modules->isEnabled('social_commerce', $company)) {
            $this->wait($company, $thread);

            return;
        }
        $to = $commentId ?? $thread->customer_ref;
        $understood = $this->replies->understand($company, $in->text);
        $answer = $understood === null ? null : $this->replies->answer($company, $page, $understood['intent'], $understood['locale'], $items);
        $this->book->query(Message::class, $company)->whereKey($in->getKey())->update(['intent' => $understood['intent'] ?? null, 'locale' => $understood['locale'] ?? null, 'item_ids' => $items === [] ? null : json_encode(array_values($items))]);

        if ($answer === null) {
            $thread->refresh();
            $unknown = $thread->unknown_count + 1;
            $this->book->query(Thread::class, $company)->whereKey($thread->getKey())->update(['unknown_count' => $unknown, 'updated_at' => now()]);
            if ($unknown >= (int) $this->rules->get('social_commerce.handoff_after_unknown', $context) && $thread->status !== Thread::WAITING) {
                $locale = $understood['locale'] ?? $this->localeOf($in->text);
                $handoff = $this->replies->answer($company, $page, 'handoff', $locale, []);
                if ($handoff !== null) {
                    $this->send($company, $page, $thread, $thread->kind === Thread::COMMENT ? Message::PUBLIC : Message::PRIVATE, $handoff, $to, Message::BOT, null, 'handoff', $locale, []);
                }
                $this->wait($company, $thread);
            }

            return;
        }

        // The same question was answered a moment ago: not again.
        $cooldown = (int) $this->rules->get('social_commerce.reply_cooldown_minutes', $context);
        $recent = $this->book->query(Message::class, $company)->where('thread_id', $thread->getKey())->where('direction', Message::OUT)->where('sender', Message::BOT)
            ->where('intent', $understood['intent'])->where('status', 'sent')->where('created_at', '>=', now()->subMinutes($cooldown))->exists();
        if ($recent) {
            return;
        }

        if ($thread->kind === Thread::COMMENT && $understood['intent'] === 'price' && ! $page->price_public) {
            $sent = $this->send($company, $page, $thread, Message::PRIVATE, $answer, $to, Message::BOT, null, 'price', $understood['locale'], $items, privateReply: true);
            $notice = $this->replies->answer($company, $page, 'notice', $understood['locale'], []);
            if ($sent->status === 'sent' && $notice !== null) {
                $this->send($company, $page, $thread, Message::PUBLIC, $notice, $to, Message::BOT, null, 'notice', $understood['locale'], []);
            }
        } else {
            $this->send($company, $page, $thread, $thread->kind === Thread::COMMENT ? Message::PUBLIC : Message::PRIVATE, $answer, $to, Message::BOT, null, $understood['intent'], $understood['locale'], $items);
        }
        $this->book->query(Thread::class, $company)->whereKey($thread->getKey())->where('status', '!=', Thread::WAITING)->update(['unknown_count' => 0, 'status' => Thread::OPEN, 'updated_at' => now()]);
    }

    /**
     * Sends one answer and keeps it in the thread (sent or failed, never thrown: the inbox shows it).
     *
     * @param  list<string>  $items
     */
    private function send(Organization $company, SocialPage $page, Thread $thread, string $channel, string $text, string $to, string $sender, ?User $actor, ?string $intent, ?string $locale, array $items, ?string $opId = null, bool $privateReply = false): Message
    {
        $status = 'sent';
        $error = null;
        $fbId = null;
        try {
            $fbId = match (true) {
                $privateReply => $this->sender->privateReply($page, $to, $text),
                $thread->kind === Thread::COMMENT => $this->sender->underComment($page, $to, $text),
                default => $this->sender->inMessenger($page, $to, $text),
            };
        } catch (MetaException $e) {
            [$status, $error] = ['failed', $e->errorCode()];
            if ($e->needsReconnect()) {
                $this->pages->needsReconnect($company, $page, $e->errorCode());
            }
        } catch (SocialCommerceException) {
            [$status, $error] = ['failed', 'page_unusable'];
        }

        $out = $this->book->query(Message::class, $company)->create([
            'organization_id' => $company->getKey(), 'thread_id' => $thread->getKey(), 'direction' => Message::OUT, 'sender' => $sender,
            'sender_user_id' => $actor?->getKey(), 'channel' => $channel, 'text' => $text, 'fb_id' => $fbId !== '' ? $fbId : null,
            'intent' => $intent, 'locale' => $locale, 'item_ids' => $items === [] ? null : array_values($items), 'status' => $status, 'error_code' => $error, 'op_id' => $opId,
        ]);
        if ($status === 'sent') {
            $this->book->query(Thread::class, $company)->whereKey($thread->getKey())->update(['last_out_at' => now()]);
        }

        return $out;
    }

    private function thread(Organization $company, SocialPage $page, string $kind, string $key, string $customer, string $name, ?string $fbPost): Thread
    {
        $query = fn () => $this->book->query(Thread::class, $company)->where('page_id', $page->getKey())->where('thread_key', $key);
        $thread = $query()->first();
        if ($thread === null) {
            try {
                $thread = $this->book->query(Thread::class, $company)->create([
                    'organization_id' => $company->getKey(), 'page_id' => $page->getKey(), 'kind' => $kind, 'thread_key' => mb_substr($key, 0, 200),
                    'customer_ref' => $customer, 'customer_name' => $name !== '' ? mb_substr($name, 0, 150) : null, 'fb_post_id' => $fbPost,
                    'status' => Thread::OPEN, 'unknown_count' => 0, 'version' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                $thread = $query()->firstOrFail();
            }
        }
        if ($thread->status === Thread::DONE) {
            $thread->forceFill(['status' => Thread::OPEN])->save();
        }

        return $thread;
    }

    private function incoming(Organization $company, Thread $thread, string $channel, string $text, string $fbId): Message
    {
        $in = $this->book->query(Message::class, $company)->create([
            'organization_id' => $company->getKey(), 'thread_id' => $thread->getKey(), 'direction' => Message::IN, 'sender' => Message::CUSTOMER,
            'channel' => $channel, 'text' => mb_substr($text, 0, 5000), 'fb_id' => $fbId,
        ]);
        $this->book->query(Thread::class, $company)->whereKey($thread->getKey())->update(['last_in_at' => now(), 'updated_at' => now()]);

        return $in;
    }

    /** True the first time Meta's id is seen at this company (Meta sends some events twice). */
    private function firstTime(Organization $company, string $eventId): bool
    {
        try {
            $this->book->query(WebhookEvent::class, $company)->create(['organization_id' => $company->getKey(), 'event_id' => mb_substr($eventId, 0, 150)]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function wait(Organization $company, Thread $thread): void
    {
        $this->book->query(Thread::class, $company)->whereKey($thread->getKey())->update(['status' => Thread::WAITING, 'updated_at' => now()]);
    }

    /**
     * Items of the company's post with this Facebook id.
     *
     * @return list<string>
     */
    private function postItems(Organization $company, string $fbPostId): array
    {
        if ($fbPostId === '') {
            return [];
        }
        $post = $this->book->query(Post::class, $company)->where('fb_post_id', $fbPostId)->value('id');

        return $post === null ? [] : array_column($this->posts->productsOf($company, $post), 'item_id');
    }

    private function lastCommentId(Organization $company, Thread $thread): ?string
    {
        return $this->book->query(Message::class, $company)->where('thread_id', $thread->getKey())->where('direction', Message::IN)
            ->orderByDesc('created_at')->orderByDesc('id')->value('fb_id');
    }

    /** Bangla script: answer in Bangla; otherwise in English. */
    private function localeOf(string $text): string
    {
        return preg_match('/\p{Bengali}/u', $text) === 1 ? 'bn' : 'en';
    }
}
