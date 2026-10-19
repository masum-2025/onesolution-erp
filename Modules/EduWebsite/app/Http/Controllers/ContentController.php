<?php

namespace Modules\EduWebsite\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Http\Controllers\Concerns\FindsSite;
use Modules\EduWebsite\Http\Requests\MediaRequest;
use Modules\EduWebsite\Http\Requests\NoticeRequest;
use Modules\EduWebsite\Http\WebsitePresenter;
use Modules\EduWebsite\Models\Media;
use Modules\EduWebsite\Models\Notice;
use Modules\EduWebsite\Services\MediaStore;
use Modules\EduWebsite\Services\Notices;
use Modules\EduWebsite\Services\Place;

/**
 * The website's notices (a published notice shows at once) and its
 * pictures and PDFs: read (edu_website.view), changed (edu_website.manage).
 * Uploads are a few a minute (throttle edu-website-uploads).
 */
class ContentController extends Controller
{
    use FindsSite;

    public function __construct(
        private Notices $notices,
        private MediaStore $media,
        private Place $place,
        private WebsitePresenter $presenter,
    ) {}

    public function notices(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.view');
        $site = $this->siteOf($company);
        $rows = $this->place->query(Notice::class, $company)->where('site_id', $site->getKey())
            ->orderByDesc('pinned')->orderByDesc('published_on')->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (Notice $notice) => $this->presenter->notice($notice))->values()]);
    }

    public function storeNotice(NoticeRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');

        return response()->json(['data' => $this->presenter->notice($this->notices->save($company, $this->siteOf($company), null, $request->validated(), $request->user()))], 201);
    }

    public function updateNotice(NoticeRequest $request, string $organization, string $notice): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $site = $this->siteOf($company);
        $found = $this->recordOf(Notice::class, $company, $site, $notice, WebsiteException::noticeNotFound());

        return response()->json(['data' => $this->presenter->notice($this->notices->save($company, $site, $found, $request->validated(), $request->user()))]);
    }

    public function destroyNotice(Request $request, string $organization, string $notice): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $found = $this->recordOf(Notice::class, $company, $this->siteOf($company), $notice, WebsiteException::noticeNotFound());
        $this->notices->remove($company, $found, (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'], $request->user());

        return response()->json(['data' => ['removed' => true]]);
    }

    public function media(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.view');
        $site = $this->siteOf($company);
        $kind = $request->validate(['kind' => ['nullable', 'in:image,pdf']])['kind'] ?? null;
        $rows = $this->place->query(Media::class, $company)->where('site_id', $site->getKey())
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))->orderByDesc('created_at')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (Media $media) => $this->presenter->media($media, $site->slug))->values()]);
    }

    public function upload(MediaRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $data = $request->validated();
        $site = $this->siteOf($company);

        return response()->json(['data' => $this->presenter->media($this->media->store($company, $site, $data['file'], $request->user()), $site->slug)], 201);
    }

    public function destroyMedia(Request $request, string $organization, string $media): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.manage');
        $found = $this->recordOf(Media::class, $company, $this->siteOf($company), $media, WebsiteException::mediaNotFound());
        $this->media->remove($company, $found);

        return response()->json(['data' => ['removed' => true]]);
    }
}
