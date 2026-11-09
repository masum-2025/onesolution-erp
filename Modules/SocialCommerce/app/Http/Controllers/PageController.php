<?php

namespace Modules\SocialCommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Meta\Exceptions\MetaException;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;
use Modules\SocialCommerce\Http\Requests\SocialRequest;
use Modules\SocialCommerce\Http\SocialPresenter;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Services\Pages;
use Modules\SocialCommerce\Services\SocialBook;

/**
 * A company's Facebook pages: seen with social_commerce.view, connected
 * and disconnected with social_commerce.connect (at the company; a branch
 * in the address means its company). Tokens never leave the server.
 */
class PageController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private SocialBook $book, private Pages $pages, private SocialPresenter $presenter) {}

    public function index(string $organization): JsonResponse
    {
        $company = $this->company($organization);
        abort_unless(Gate::any(['social_commerce.view', 'social_commerce.connect'], $company), 403);
        try {
            $app = $this->pages->appFor($company);
        } catch (MetaException) {
            $app = null;
        }

        return response()->json(['data' => [
            'pages' => $this->book->query(SocialPage::class, $company)->where('status', '!=', SocialPage::DISCONNECTED)->orderBy('name')->get()
                ->map(fn (SocialPage $page) => $this->presenter->page($page))->values()->all(),
            // Whether Facebook is set up for this company's partner (else connecting cannot start), and through which app.
            'app' => $app === null ? null : ['name' => $app->app_name],
            'can_connect' => Gate::allows('social_commerce.connect', $company),
        ]]);
    }

    /** Starts connecting: the address of Meta's sign-in. */
    public function start(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('social_commerce.connect', $company);

        return response()->json(['data' => ['url' => $this->pages->start($company, $request->user(), url((string) config('meta.redirect_path')))]]);
    }

    /** The pages found after signing in (?state). */
    public function candidates(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('social_commerce.connect', $company);
        $state = $request->validate(['state' => ['required', 'string', 'size:48']])['state'];

        return response()->json(['data' => $this->pages->candidates($company, $request->user(), $state)]);
    }

    public function save(SocialRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('social_commerce.connect', $company);
        $saved = $this->pages->save($company, $request->user(), $request->validated('state'), $request->validated('page_ids'));

        return response()->json(['data' => array_map(fn (SocialPage $page) => $this->presenter->page($page), $saved), 'message' => __('social_commerce::social.messages.connected', ['count' => count($saved)])], 201);
    }

    public function disconnect(SocialRequest $request, string $organization, string $page): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('social_commerce.connect', $company);
        $found = $this->book->query(SocialPage::class, $company)->whereKey($page)->first() ?? throw SocialCommerceException::notFound('page');
        $done = $this->pages->disconnect($company, $found, (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->presenter->page($done), 'message' => __('social_commerce::social.messages.disconnected')]);
    }

    private function company(string $organization): Organization
    {
        return $this->book->companyOf($this->findVisible($organization));
    }
}
