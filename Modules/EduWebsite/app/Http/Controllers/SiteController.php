<?php

namespace Modules\EduWebsite\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Sites\SiteAddress;
use App\Platform\Sites\SiteResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Http\Controllers\Concerns\FindsSite;
use Modules\EduWebsite\Http\Requests\SiteRequest;
use Modules\EduWebsite\Http\WebsitePresenter;
use Modules\EduWebsite\Models\Media;
use Modules\EduWebsite\Models\Page;
use Modules\EduWebsite\Services\Place;
use Modules\EduWebsite\Services\Sites;

/**
 * The company's website: what it is (edu_website.view), starting it and
 * changing its address, publishing and taking it offline
 * (edu_website.publish), other settings (edu_website.manage), and a
 * preview link of the draft (an hour, signed).
 */
class SiteController extends Controller
{
    use FindsSite;

    /** How long a preview link works. */
    private const PREVIEW_MINUTES = 60;

    public function __construct(
        private Sites $sites,
        private Place $place,
        private WebsitePresenter $presenter,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function show(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.view');
        $site = $this->sites->of($company);

        $offered = $this->rule($company, 'edu_website.templates');

        return response()->json(['data' => $site === null ? null : $this->presenter->site($site), 'meta' => [
            'templates' => $offered['value'],
            // Every template, those a level above turned off marked (with where that was set).
            'template_choices' => array_map(fn (string $key) => ['key' => $key, 'offered' => in_array($key, $offered['value'], true)], Sites::TEMPLATES),
            'template_source' => $offered['source'],
            'max_pages' => $this->rule($company, 'edu_website.max_pages'),
            'max_media_mb' => $this->rule($company, 'edu_website.max_media_mb'),
            'page_count' => $site === null ? 0 : $this->place->query(Page::class, $company)->where('site_id', $site->getKey())->count(),
            'has_changes' => $site !== null && $this->sites->hasChanges($company, $site),
            'languages' => LanguageRegistry::codes(),
            'sites_domain' => (string) config('sites.domain'),
            'can_manage' => Gate::allows('edu_website.manage', $company),
            'can_publish' => Gate::allows('edu_website.publish', $company),
        ]]);
    }

    /** Whether an address can be used (its form, and no other website has it); a few a minute. */
    public function addressCheck(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.publish');
        $slug = strtolower((string) $request->validate(['slug' => ['required', 'string', 'max:63']])['slug']);
        $valid = strlen($slug) >= 3 && preg_match('/^'.SiteResolver::SLUG_PATTERN.'$/', $slug) === 1;
        $free = $valid && ! SiteAddress::query()->where('slug', $slug)->where('organization_id', '!=', $company->getKey())->exists();

        return response()->json(['data' => ['slug' => $slug, 'valid' => $valid, 'available' => $free]]);
    }

    public function start(SiteRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.publish');
        if ($this->sites->of($company) !== null) {
            throw WebsiteException::slugTaken();
        }

        return response()->json(['data' => $this->presenter->site($this->sites->start($company, $request->validated(), $request->user()))], 201);
    }

    public function update(SiteRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $site = $this->siteOf($company);
        $data = $request->validated();
        if (isset($data['slug']) && $data['slug'] !== $site->slug) {
            Gate::authorize('edu_website.publish', $company);
        }
        if (! empty($data['logo_media_id']) && ! $this->place->query(Media::class, $company)->where('site_id', $site->getKey())->where('kind', 'image')->whereKey($data['logo_media_id'])->exists()) {
            throw WebsiteException::mediaNotFound();
        }

        return response()->json(['data' => $this->presenter->site($this->sites->update($company, $site, $data, $request->user()))]);
    }

    public function publish(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.publish');
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];

        return response()->json(['data' => $this->presenter->site($this->sites->publish($company, $this->siteOf($company), $version, $request->user()))]);
    }

    public function offline(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.publish');
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];

        return response()->json(['data' => $this->presenter->site($this->sites->takeOffline($company, $this->siteOf($company), $version, $request->user()))]);
    }

    /**
     * A rule's value here and where it comes from (set here, a level above, the default) and whether it is locked.
     *
     * @return array{value: mixed, source: array<string, mixed>}
     */
    private function rule(Organization $company, string $key): array
    {
        $resolved = $this->rules->explain($key, $this->contexts->forOrganization($company));

        return ['value' => $resolved->value, 'source' => [
            'kind' => match (true) {
                $resolved->sourceLevel === null => 'default',
                $resolved->sourceScopeId === $company->getKey() => 'self',
                default => 'inherited',
            },
            'level' => $resolved->sourceLevel,
            'name' => $resolved->sourceName,
            'locked' => $resolved->isLockedByAncestor(),
        ]];
    }

    /** A link to the draft, for an hour (signed; not indexed by search engines). */
    public function preview(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $site = $this->siteOf($company);
        // A page of the draft (its address), else the home page.
        $page = $request->validate(['page' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/']])['page'] ?? null;

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('edu-website.public', now()->addMinutes(self::PREVIEW_MINUTES), ['slug' => $site->slug, 'path' => $page === null ? 'preview' : "preview/{$page}"]),
            'expires_in_minutes' => self::PREVIEW_MINUTES,
        ]]);
    }
}
