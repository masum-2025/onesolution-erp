<?php

namespace Modules\SocialCommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Services\Stock;
use Modules\OnlineOrders\Services\Orders;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;
use Modules\SocialCommerce\Http\Requests\SocialRequest;
use Modules\SocialCommerce\Models\Message;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Models\Thread;
use Modules\SocialCommerce\Services\Conversations;
use Modules\SocialCommerce\Services\SocialBook;

/**
 * The inbox: a company's comment and Messenger conversations, read and
 * answered with social_commerce.inbox (at the company). Customers are shown
 * as Facebook gave them; their Facebook ids never leave the server.
 */
class InboxController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private SocialBook $book, private Conversations $conversations, private Stock $stock) {}

    /** Conversations (?status, ?page_id), the latest news first. */
    public function index(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        $filters = $request->validate(['status' => ['nullable', 'in:'.implode(',', Thread::STATUSES)], 'page_id' => ['nullable', 'string', 'size:26']]);
        $query = $this->book->query(Thread::class, $company)->orderByDesc('last_in_at')->orderByDesc('id')->limit(200);
        foreach (['status', 'page_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        $threads = $query->get();
        $pages = $this->book->query(SocialPage::class, $company)->whereIn('id', $threads->pluck('page_id')->unique()->all())->pluck('name', 'id');
        $last = $this->book->query(Message::class, $company)->whereIn('thread_id', $threads->modelKeys())->orderBy('created_at')->orderBy('id')->get()->groupBy('thread_id')->map->last();

        return response()->json(['data' => $threads->map(fn (Thread $thread) => [
            ...$this->head($thread),
            'page' => $pages[$thread->page_id] ?? null,
            'last' => ($message = $last->get($thread->getKey())) === null ? null : ['text' => mb_substr($message->text, 0, 140), 'sender' => $message->sender, 'at' => $message->created_at?->toIso8601String()],
        ])->values(), 'meta' => ['waiting' => $this->conversations->waiting($company)]]);
    }

    public function show(string $organization, string $thread): JsonResponse
    {
        $company = $this->company($organization);

        return response()->json(['data' => $this->full($company, $this->find($company, $thread))]);
    }

    public function reply(SocialRequest $request, string $organization, string $thread): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->find($company, $thread);
        $this->conversations->reply($company, $found, $request->validated('text'), $request->validated('op_id'), $request->user());

        return response()->json(['data' => $this->full($company, $this->find($company, $thread)), 'message' => __('social_commerce::social.messages.reply_sent')], 201);
    }

    /** claim | done | reopen */
    public function mark(SocialRequest $request, string $organization, string $thread, string $step): JsonResponse
    {
        $company = $this->company($organization);
        $done = $this->conversations->mark($company, $this->find($company, $thread), (int) $request->validated('base_version'), $step, $request->user());

        return response()->json(['data' => $this->full($company, $done)]);
    }

    // ---------------------------------------------------------------- inside

    private function company(string $organization): Organization
    {
        $company = $this->book->companyOf($this->findVisible($organization));
        Gate::authorize('social_commerce.inbox', $company);

        return $company;
    }

    private function find(Organization $company, string $id): Thread
    {
        return $this->book->query(Thread::class, $company)->whereKey($id)->first() ?? throw SocialCommerceException::notFound('thread');
    }

    /**
     * @return array<string, mixed>
     */
    private function head(Thread $thread): array
    {
        return [
            'id' => $thread->getKey(),
            'kind' => $thread->kind,
            'customer' => $thread->customer_name,
            'status' => $thread->status,
            'assigned_to' => $thread->assigned_to === null ? null : User::query()->whereKey($thread->assigned_to)->value('name'),
            'mine' => $thread->assigned_to !== null && $thread->assigned_to === request()->user()?->getKey(),
            'last_in_at' => $thread->last_in_at?->toIso8601String(),
            'version' => $thread->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function full(Organization $company, Thread $thread): array
    {
        $messages = $this->book->query(Message::class, $company)->where('thread_id', $thread->getKey())->orderBy('created_at')->orderBy('id')->get();
        $people = User::query()->whereKey($messages->pluck('sender_user_id')->filter()->unique()->all())->pluck('name', 'id');
        $itemIds = $messages->pluck('item_ids')->flatten()->filter()->unique()->values()->all();
        $names = collect($itemIds === [] ? [] : $this->stock->items($company, $itemIds))->pluck('name', 'id');
        $page = $this->book->query(SocialPage::class, $company)->find($thread->page_id);

        return [
            ...$this->head($thread),
            'page' => $page === null ? null : ['id' => $page->getKey(), 'name' => $page->name, 'picture_url' => $page->picture_url, 'usable' => $page->status === SocialPage::CONNECTED],
            // The order being taken in the chat (where it is), and the last one placed from it.
            'draft' => $thread->order_draft === null ? null : [
                'step' => $thread->order_draft['step'] ?? null,
                'product' => isset($thread->order_draft['item_id']) ? ($this->stock->items($company, [(string) $thread->order_draft['item_id']])[0]['name'] ?? null) : null,
                'quantity' => $thread->order_draft['quantity'] ?? null,
                'name' => $thread->order_draft['name'] ?? null,
                'phone' => $thread->order_draft['phone'] ?? null,
                'started_at' => $thread->draft_at?->toIso8601String(),
            ],
            'order' => $thread->last_order_id === null || ! class_exists(Orders::class) ? null : app(Orders::class)->brief($company, $thread->last_order_id),
            'messages' => $messages->map(fn (Message $message) => [
                'id' => $message->getKey(),
                'direction' => $message->direction,
                'sender' => $message->sender,
                'by' => $message->sender_user_id === null ? null : ($people[$message->sender_user_id] ?? null),
                'channel' => $message->channel,
                'text' => $message->text,
                'intent' => $message->intent,
                'products' => collect((array) $message->item_ids)->map(fn ($id) => $names[$id] ?? null)->filter()->values()->all(),
                'status' => $message->status,
                'error' => $message->error_code === null ? null : __(trans()->has("social_commerce::social.failures.{$message->error_code}") ? "social_commerce::social.failures.{$message->error_code}" : 'social_commerce::social.failures.rejected'),
                'at' => $message->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
