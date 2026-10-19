<?php

namespace Modules\EduWebsite\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Http\Controllers\Concerns\FindsSite;
use Modules\EduWebsite\Http\Requests\SiteRequest;
use Modules\EduWebsite\Http\WebsitePresenter;
use Modules\EduWebsite\Models\Media;
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

    public function __construct(private Sites $sites, private Place $place, private WebsitePresenter $presenter) {}

    public function show(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.view');
        $site = $this->sites->of($company);

        return response()->json(['data' => $site === null ? null : $this->presenter->site($site), 'meta' => [
            'templates' => $this->sites->templates($company),
            'can_manage' => Gate::allows('edu_website.manage', $company),
            'can_publish' => Gate::allows('edu_website.publish', $company),
        ]]);
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

    /** A link to the draft, for an hour (signed; not indexed by search engines). */
    public function preview(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $site = $this->siteOf($company);

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('edu-website.public', now()->addMinutes(self::PREVIEW_MINUTES), ['slug' => $site->slug, 'path' => 'preview']),
            'expires_in_minutes' => self::PREVIEW_MINUTES,
        ]]);
    }
}
