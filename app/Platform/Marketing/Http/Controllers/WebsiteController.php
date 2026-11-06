<?php

namespace App\Platform\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Marketing\Http\Requests\BlockRequest;
use App\Platform\Marketing\Http\Requests\OrderRequest;
use App\Platform\Marketing\Http\Requests\PageRequest;
use App\Platform\Marketing\Http\Requests\UpdateWebsiteRequest;
use App\Platform\Marketing\Models\Block;
use App\Platform\Marketing\Models\Page;
use App\Platform\Marketing\Models\Site;
use App\Platform\Marketing\Models\Version;
use App\Platform\Marketing\Services\BlockTypes;
use App\Platform\Marketing\Services\Pages;
use App\Platform\Marketing\Services\SiteSettings;
use App\Platform\Marketing\Services\Websites;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner console: the partner's company website. Everyone in the console
 * sees it; owners and sales staff write pages and sections (drafts); only
 * owners change the site's settings, publish, go back to an earlier version
 * or take it offline. The partner always comes from the context.
 */
class WebsiteController extends Controller
{
    use PartnerConsole;

    private const WRITERS = [PartnerUserRole::Owner, PartnerUserRole::Sales];

    public function __construct(private Websites $websites, private Pages $pages) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->websites->of($this->partner()))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $site = $this->websites->create($this->partner(), $request->user());

        return response()->json(['data' => $this->payload($site), 'message' => __('marketing.messages.created')], 201);
    }

    public function update(UpdateWebsiteRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $data = $request->validated();
        $site = $this->websites->update($this->site(), $data, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->payload($site), 'message' => __('marketing.messages.saved')]);
    }

    public function publish(Request $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $site = $this->websites->publish($this->site(), $request->user());

        return response()->json(['data' => $this->payload($site), 'message' => __('marketing.messages.published')]);
    }

    public function offline(Request $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $site = $this->websites->takeOffline($this->site(), $request->user());

        return response()->json(['data' => $this->payload($site), 'message' => __('marketing.messages.offline')]);
    }

    public function versions(): JsonResponse
    {
        $site = $this->site();

        return response()->json(['data' => Version::query()->where('site_id', $site->getKey())->orderByDesc('published_at')->get(['id', 'published_at', 'published_by'])]);
    }

    public function restore(Request $request, string $version): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $site = $this->websites->restore($this->site(), $version, $request->user());

        return response()->json(['data' => $this->payload($site), 'message' => __('marketing.messages.restored')]);
    }

    public function previewLink(): JsonResponse
    {
        return response()->json(['data' => ['url' => $this->websites->previewUrl($this->site())]]);
    }

    public function storePage(PageRequest $request): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $page = $this->pages->create($this->site(), $request->validated());

        return response()->json(['data' => $this->pageData($page), 'message' => __('marketing.messages.saved')], 201);
    }

    public function updatePage(PageRequest $request, string $page): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $site = $this->site();
        $data = $request->validated();
        $row = $this->pages->update($site, $this->pages->find($site, $page), $data, (int) $data['base_version']);

        return response()->json(['data' => $this->pageData($row), 'message' => __('marketing.messages.saved')]);
    }

    public function destroyPage(Request $request, string $page): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $site = $this->site();
        $this->pages->delete($site, $this->pages->find($site, $page), $request->user());

        return response()->json(['message' => __('marketing.messages.deleted')]);
    }

    public function orderPages(OrderRequest $request): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $this->pages->reorder($this->site(), $request->validated('ids'));

        return response()->json(['message' => __('marketing.messages.saved')]);
    }

    public function storeBlock(BlockRequest $request, string $page): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $site = $this->site();
        $block = $this->pages->addBlock($site, $this->pages->find($site, $page), $request->validated());

        return response()->json(['data' => $this->blockData($block), 'message' => __('marketing.messages.saved')], 201);
    }

    public function updateBlock(BlockRequest $request, string $block): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $site = $this->site();
        $data = $request->validated();
        $row = $this->pages->updateBlock($site, $this->pages->findBlock($site, $block), $data, (int) $data['base_version']);

        return response()->json(['data' => $this->blockData($row), 'message' => __('marketing.messages.saved')]);
    }

    public function destroyBlock(string $block): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $site = $this->site();
        $this->pages->deleteBlock($this->pages->findBlock($site, $block));

        return response()->json(['message' => __('marketing.messages.deleted')]);
    }

    public function orderBlocks(OrderRequest $request, string $page): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $site = $this->site();
        $this->pages->reorderBlocks($this->pages->find($site, $page), $request->validated('ids'));

        return response()->json(['message' => __('marketing.messages.saved')]);
    }

    private function site(): Site
    {
        return $this->websites->require($this->partner());
    }

    /** @return array<string, mixed> */
    private function payload(?Site $site): array
    {
        $writer = $this->hasRole(...self::WRITERS);
        $owner = $this->hasRole(PartnerUserRole::Owner);
        $choices = [
            'templates' => Site::TEMPLATES,
            'block_types' => BlockTypes::TYPES,
            'effects' => BlockTypes::EFFECTS,
            'product_styles' => BlockTypes::PRODUCT_STYLES,
            'slide_visuals' => BlockTypes::SLIDE_VISUALS,
            'card_visuals' => BlockTypes::CARD_VISUALS,
            'colors' => BlockTypes::COLORS,
            'icons' => BlockTypes::ICONS,
            'social' => SiteSettings::SOCIAL,
            'locales' => LanguageRegistry::codes(),
            'modules' => collect(app(ModuleRegistry::class)->keys())->map(fn ($key) => ['key' => $key, 'name' => app(ModuleRegistry::class)->get($key)->label()])->values(),
            // Pricing names the catalogue's plans (house) or the partner's own active plans.
            'plans' => $planKeys = app(BlockTypes::class)->planKeys($this->partner()),
            'plan_names' => (object) $this->planNames($planKeys),
        ];
        if ($site === null) {
            return ['site' => null, 'choices' => $choices, 'can' => ['create' => $writer]];
        }
        $pages = Page::query()->where('site_id', $site->getKey())->orderBy('sort_order')->get();
        $blocks = Block::query()->where('site_id', $site->getKey())->orderBy('sort_order')->get()->groupBy('page_id');
        // The section forms, described by the rules that check them.
        $choices['fields'] = app(BlockTypes::class)->fields($site->default_locale, $this->partner());

        return [
            'site' => [
                'id' => $site->getKey(),
                'template' => $site->template,
                'locales' => $site->locales,
                'default_locale' => $site->default_locale,
                'settings' => (object) $site->settings,
                'status' => $site->status,
                'published_at' => $site->published_at?->toIso8601String(),
                'version' => $site->version,
                // Where the public sees it: the house site's address, or the partner's verified website domain.
                'address' => $this->websites->address($site),
                'pages' => $pages->map(fn (Page $page) => [...$this->pageData($page), 'blocks' => ($blocks[$page->getKey()] ?? collect())->map(fn (Block $block) => $this->blockData($block))->values()])->values(),
            ],
            'choices' => $choices,
            'can' => ['create' => false, 'write' => $writer, 'settings' => $owner, 'publish' => $owner],
        ];
    }

    /**
     * What the console shows for each plan in the pricing form: the catalogue's label, or the
     * partner plan's own name (its id is meaningless to people).
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function planNames(array $keys): array
    {
        if ($this->partner()->is_house) {
            $catalog = app(PlanCatalog::class);

            return collect($keys)->mapWithKeys(fn ($key) => [$key => $catalog->get($key)->label()])->all();
        }

        return PartnerPlan::query()->where('partner_id', $this->partner()->getKey())->whereIn('id', $keys)->get()
            ->mapWithKeys(fn (PartnerPlan $plan) => [$plan->getKey() => $plan->label()])->all();
    }

    /** @return array<string, mixed> */
    private function pageData(Page $page): array
    {
        return [
            'id' => $page->getKey(), 'slug' => $page->slug, 'is_home' => $page->is_home, 'title' => (object) $page->title,
            'seo' => (object) ($page->seo ?? []), 'in_menu' => $page->in_menu, 'sort_order' => $page->sort_order, 'version' => $page->version,
        ];
    }

    /** @return array<string, mixed> */
    private function blockData(Block $block): array
    {
        return [
            'id' => $block->getKey(), 'page_id' => $block->page_id, 'type' => $block->type, 'data' => (object) $block->data,
            'sort_order' => $block->sort_order, 'is_visible' => $block->is_visible,
            'visible_from' => $block->visible_from?->toDateString(), 'visible_until' => $block->visible_until?->toDateString(), 'version' => $block->version,
        ];
    }
}
