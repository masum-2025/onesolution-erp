<?php

namespace Modules\EduWebsite\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Http\Controllers\Concerns\FindsSite;
use Modules\EduWebsite\Http\Requests\BlockRequest;
use Modules\EduWebsite\Http\Requests\PageRequest;
use Modules\EduWebsite\Http\WebsitePresenter;
use Modules\EduWebsite\Models\Block;
use Modules\EduWebsite\Models\Page;
use Modules\EduWebsite\Services\Pages;
use Modules\EduWebsite\Services\Place;

/**
 * The draft pages and their blocks: read (edu_website.view), changed
 * (edu_website.manage). Nothing here shows to the public until the site is
 * published.
 */
class PageController extends Controller
{
    use FindsSite;

    public function __construct(private Pages $pages, private Place $place, private WebsitePresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.view');
        $site = $this->siteOf($company);
        $pages = $this->place->query(Page::class, $company)->where('site_id', $site->getKey())->orderBy('sort_order')->get();
        $blocks = $this->place->query(Block::class, $company)->whereIn('page_id', $pages->modelKeys())->orderBy('sort_order')->get()->groupBy('page_id');

        return response()->json(['data' => $pages->map(fn (Page $page) => $this->presenter->page($page, $blocks->get($page->getKey(), collect())))->values()]);
    }

    public function store(PageRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');

        return response()->json(['data' => $this->presenter->page($this->pages->create($company, $this->siteOf($company), $request->validated()), [])], 201);
    }

    public function order(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $ids = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['string', 'size:26']])['ids'];
        $this->pages->orderPages($company, $this->siteOf($company), $ids);

        return response()->json(['data' => ['ordered' => true]]);
    }

    public function update(PageRequest $request, string $organization, string $page): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $found = $this->recordOf(Page::class, $company, $this->siteOf($company), $page, WebsiteException::pageNotFound());

        return response()->json(['data' => $this->presenter->page($this->pages->update($company, $found, $request->validated()))]);
    }

    public function destroy(Request $request, string $organization, string $page): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $found = $this->recordOf(Page::class, $company, $this->siteOf($company), $page, WebsiteException::pageNotFound());
        $this->pages->remove($company, $found, (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version']);

        return response()->json(['data' => ['removed' => true]]);
    }

    public function addBlock(BlockRequest $request, string $organization, string $page): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $found = $this->recordOf(Page::class, $company, $this->siteOf($company), $page, WebsiteException::pageNotFound());

        return response()->json(['data' => $this->presenter->block($this->pages->addBlock($company, $found, $request->validated('type'), $request->blockData()))], 201);
    }

    public function updateBlock(BlockRequest $request, string $organization, string $page, string $block): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $found = $this->blockOf($company, $page, $block);

        return response()->json(['data' => $this->presenter->block($this->pages->updateBlock($company, $found, $request->blockData(), (int) $request->validated('base_version')))]);
    }

    public function removeBlock(Request $request, string $organization, string $page, string $block): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $found = $this->blockOf($company, $page, $block);
        $this->pages->removeBlock($company, $found, (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version']);

        return response()->json(['data' => ['removed' => true]]);
    }

    public function orderBlocks(BlockRequest $request, string $organization, string $page): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $found = $this->recordOf(Page::class, $company, $this->siteOf($company), $page, WebsiteException::pageNotFound());
        $this->pages->order($company, $found, $request->validated('ids'));

        return response()->json(['data' => ['ordered' => true]]);
    }

    private function blockOf(Organization $company, string $page, string $block): Block
    {
        $found = $this->recordOf(Page::class, $company, $this->siteOf($company), $page, WebsiteException::pageNotFound());

        return $this->place->query(Block::class, $company)->where('page_id', $found->getKey())->whereKey($block)->first() ?? throw WebsiteException::blockNotFound();
    }
}
