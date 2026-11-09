<?php

namespace Modules\SocialCommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;
use Modules\SocialCommerce\Http\Requests\SocialRequest;
use Modules\SocialCommerce\Http\SocialPresenter;
use Modules\SocialCommerce\Models\Post;
use Modules\SocialCommerce\Models\PostMedia;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Services\Posts;
use Modules\SocialCommerce\Services\SocialBook;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Facebook posts of a company: read with social_commerce.view, written and
 * sent on with social_commerce.compose, approved or turned down with
 * social_commerce.approve (all at the company; a branch in the address
 * means its company).
 */
class PostController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private SocialBook $book, private Posts $posts, private SocialPresenter $presenter) {}

    /** Posts (?status), newest first. */
    public function index(Request $request, string $organization): JsonResponse
    {
        $company = $this->readable($organization);
        $status = $request->validate(['status' => ['nullable', 'in:'.implode(',', Post::STATUSES)]])['status'] ?? null;
        $query = $this->book->query(Post::class, $company)->orderByDesc('created_at')->orderByDesc('id')->limit(300);
        if ($status !== null) {
            $query->where('status', $status);
        }

        return response()->json(['data' => $this->presenter->list($company, $query->get())]);
    }

    /** What the post screen needs: the pages it may post on, the rules, the footer line per language. */
    public function options(string $organization): JsonResponse
    {
        $company = $this->readable($organization);
        $context = app(RuleContextFactory::class)->forOrganization($company);
        $rules = app(RuleResolver::class);
        $locales = LanguageRegistry::codes();

        return response()->json(['data' => [
            'pages' => $this->book->query(SocialPage::class, $company)->where('status', '!=', SocialPage::DISCONNECTED)->orderBy('name')->get()
                ->map(fn (SocialPage $page) => ['id' => $page->getKey(), 'name' => $page->name, 'picture_url' => $page->picture_url, 'usable' => $page->usable(), 'status' => $page->status])->values()->all(),
            'locales' => $locales,
            'footers' => array_combine($locales, array_map(fn (string $locale) => $this->posts->footer($company, $locale), $locales)),
            'needs_approval' => $rules->get('social_commerce.posts_need_approval', $context) === true,
            'max_images' => (int) $rules->get('social_commerce.max_images_per_post', $context),
            'max_image_mb' => (int) $rules->get('social_commerce.max_image_mb', $context),
            'min_schedule_minutes' => (int) $rules->get('social_commerce.min_schedule_minutes', $context),
            'can_compose' => Gate::allows('social_commerce.compose', $company),
            'can_approve' => Gate::allows('social_commerce.approve', $company),
            'can_connect' => Gate::allows('social_commerce.connect', $company),
        ]]);
    }

    /** Products to put in a post (?search, ?locale), each with the line it would carry. */
    public function items(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('social_commerce.compose', $company);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:80'], 'locale' => ['nullable', 'in:'.implode(',', LanguageRegistry::codes())]]);

        return response()->json(['data' => $this->posts->items($company, $filters['search'] ?? null, $filters['locale'] ?? app()->getLocale())]);
    }

    public function store(SocialRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('social_commerce.compose', $company);

        return response()->json(['data' => $this->presenter->post($company, $this->posts->create($company, $request->user(), $request->validated()))], 201);
    }

    public function show(string $organization, string $post): JsonResponse
    {
        $company = $this->readable($organization);

        return response()->json(['data' => $this->presenter->post($company, $this->find($company, $post))]);
    }

    public function update(SocialRequest $request, string $organization, string $post): JsonResponse
    {
        [$company, $found] = $this->allowed($organization, $post, 'social_commerce.compose');
        $data = $request->safe()->except('base_version');

        return response()->json(['data' => $this->presenter->post($company, $this->posts->revise($company, $found, (int) $request->validated('base_version'), $data, $request->user()))]);
    }

    public function destroy(SocialRequest $request, string $organization, string $post): JsonResponse
    {
        [$company, $found] = $this->allowed($organization, $post, 'social_commerce.compose');
        $this->posts->delete($company, $found, (int) $request->validated('base_version'), $request->user());

        return response()->json(['message' => __('social_commerce::social.messages.deleted')]);
    }

    /** submit | approve | reject | withdraw */
    public function step(SocialRequest $request, string $organization, string $post, string $step): JsonResponse
    {
        [$company, $found] = $this->allowed($organization, $post, in_array($step, ['approve', 'reject'], true) ? 'social_commerce.approve' : 'social_commerce.compose');
        $version = (int) $request->validated('base_version');
        $user = $request->user();
        $done = match ($step) {
            'submit' => $this->posts->submit($company, $found, $version, $user),
            'approve' => $this->posts->approve($company, $found, $version, $user),
            'reject' => $this->posts->reject($company, $found, $version, (string) $request->validated('reason'), $user),
            'withdraw' => $this->posts->withdraw($company, $found, $version, $user),
        };
        $fresh = $this->book->query(Post::class, $company)->find($done->getKey()) ?? $done;

        return response()->json(['data' => $this->presenter->post($company, $fresh), 'message' => __("social_commerce::social.messages.{$step}_{$fresh->status}")]);
    }

    public function storeMedia(SocialRequest $request, string $organization, string $post): JsonResponse
    {
        [$company, $found] = $this->allowed($organization, $post, 'social_commerce.compose');

        return response()->json(['data' => $this->presenter->post($company, $this->posts->addMedia($company, $found, (int) $request->validated('base_version'), $request->file('file'), $request->user()))], 201);
    }

    public function destroyMedia(SocialRequest $request, string $organization, string $post, string $media): JsonResponse
    {
        [$company, $found] = $this->allowed($organization, $post, 'social_commerce.compose');

        return response()->json(['data' => $this->presenter->post($company, $this->posts->removeMedia($company, $found, (int) $request->validated('base_version'), $media, $request->user()))]);
    }

    public function orderMedia(SocialRequest $request, string $organization, string $post): JsonResponse
    {
        [$company, $found] = $this->allowed($organization, $post, 'social_commerce.compose');

        return response()->json(['data' => $this->presenter->post($company, $this->posts->reorderMedia($company, $found, (int) $request->validated('base_version'), $request->validated('media_ids'), $request->user()))]);
    }

    /** A post's photo, only through a short-lived signed link (the screens get these links). */
    public function file(string $organization, string $media): StreamedResponse
    {
        $found = PostMedia::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization)->whereKey($media)->first();
        abort_if($found === null || ! Storage::disk('local')->exists($found->path), 404);

        return Storage::disk('local')->response($found->path, basename($found->path), [
            'Content-Type' => $found->mime,
            'Cache-Control' => 'private, max-age=1800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ---------------------------------------------------------------- inside

    private function company(string $organization): Organization
    {
        return $this->book->companyOf($this->findVisible($organization));
    }

    private function readable(string $organization): Organization
    {
        $company = $this->company($organization);
        abort_unless(Gate::any(['social_commerce.view', 'social_commerce.compose', 'social_commerce.approve'], $company), 403);

        return $company;
    }

    /**
     * @return array{0: Organization, 1: Post}
     */
    private function allowed(string $organization, string $post, string $permission): array
    {
        $company = $this->company($organization);
        Gate::authorize($permission, $company);

        return [$company, $this->find($company, $post)];
    }

    private function find(Organization $company, string $id): Post
    {
        return $this->book->query(Post::class, $company)->whereKey($id)->first() ?? throw SocialCommerceException::notFound('post');
    }
}
