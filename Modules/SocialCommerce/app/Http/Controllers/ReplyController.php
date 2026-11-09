<?php

namespace Modules\SocialCommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\SocialCommerce\Http\Requests\SocialRequest;
use Modules\SocialCommerce\Models\Post;
use Modules\SocialCommerce\Models\ReplyTemplate;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Services\AiReader;
use Modules\SocialCommerce\Services\Posts;
use Modules\SocialCommerce\Services\Replies;
use Modules\SocialCommerce\Services\SocialBook;

/**
 * What the bot understands and answers, per company (social_commerce.replies),
 * and a try box: type what a customer would write, see what would go back.
 */
class ReplyController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private SocialBook $book, private Replies $replies, private Posts $posts) {}

    public function index(string $organization): JsonResponse
    {
        $company = $this->company($organization);

        return response()->json(['data' => [
            'templates' => $this->replies->templates($company)->map(fn (ReplyTemplate $template) => $this->template($template))->values(),
            'locales' => LanguageRegistry::codes(),
            'posts' => $this->book->query(Post::class, $company)->whereNotNull('fb_post_id')->orderByDesc('published_at')->limit(30)->get(['id', 'message'])
                ->map(fn (Post $post) => ['id' => $post->getKey(), 'title' => mb_substr((string) $post->message, 0, 60)])->values(),
        ]]);
    }

    public function update(SocialRequest $request, string $organization, string $intent): JsonResponse
    {
        $company = $this->company($organization);
        abort_unless(in_array($intent, ReplyTemplate::INTENTS, true), 404);
        $done = $this->replies->change($company, $intent, (int) $request->validated('base_version'), (array) $request->validated('keywords'), (array) $request->validated('answers'), (bool) $request->validated('active'), $request->user());

        return response()->json(['data' => $this->template($done), 'message' => __('social_commerce::social.messages.replies_saved')]);
    }

    /** What would go back to a customer writing this (under one of the posts, or in Messenger with no product). */
    public function try(SocialRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        $text = (string) $request->validated('text');
        $understood = $this->replies->understand($company, $text);
        $by = $understood === null ? null : 'keyword';
        // SC-4: what the keywords miss, the AI assistant reads (when on; the reading counts in the month).
        $ai = app(AiReader::class);
        $fields = [];
        if ($ai->wanted($company, $text, $understood, null, true)) {
            $reading = $ai->read($company, $text);
            $fields = $reading['fields'] ?? [];
            if ($understood === null && ($reading['intent'] ?? null) !== null) {
                [$understood, $by] = [['intent' => $reading['intent'], 'locale' => $reading['locale']], 'ai'];
            }
        }
        $postId = $request->validated('post_id');
        $items = $postId !== null ? array_column($this->posts->productsOf($company, $postId), 'item_id') : $this->replies->itemsNamedIn($company, $text);
        $page = $this->book->query(SocialPage::class, $company)->where('status', SocialPage::CONNECTED)->first()
            ?? $this->book->query(SocialPage::class, $company)->make(['name' => $company->displayName()]);
        $answer = $understood === null ? null : $this->replies->answer($company, $page, $understood['intent'], $understood['locale'], $items);

        return response()->json(['data' => [
            'intent' => $understood['intent'] ?? null,
            'locale' => $understood['locale'] ?? null,
            'understood_by' => $by,
            'fields' => (object) $fields,
            'answer' => $answer,
            'handoff' => $answer === null ? $this->replies->answer($company, $page, 'handoff', $understood['locale'] ?? (preg_match('/\p{Bengali}/u', $text) ? 'bn' : 'en'), []) : null,
        ]]);
    }

    private function company(string $organization): Organization
    {
        $company = $this->book->companyOf($this->findVisible($organization));
        Gate::authorize('social_commerce.replies', $company);

        return $company;
    }

    /**
     * @return array<string, mixed>
     */
    private function template(ReplyTemplate $template): array
    {
        return [
            'intent' => $template->intent,
            'fixed' => in_array($template->intent, ReplyTemplate::FIXED, true),
            'keywords' => (object) $template->keywords,
            'answers' => (object) $template->answers,
            'active' => $template->active,
            'version' => $template->version,
        ];
    }
}
