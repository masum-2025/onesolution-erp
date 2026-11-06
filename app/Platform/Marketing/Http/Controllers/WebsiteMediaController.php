<?php

namespace App\Platform\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Marketing\Http\Requests\UpdateMediaRequest;
use App\Platform\Marketing\Http\Requests\UploadMediaRequest;
use App\Platform\Marketing\Models\Media;
use App\Platform\Marketing\Models\Site;
use App\Platform\Marketing\Services\MediaLibrary;
use App\Platform\Marketing\Services\Websites;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partner console: the website's pictures. Everyone in the console sees
 * them; owners and sales staff (who write the pages) upload, rename and
 * remove them. Only the partner's own site, from the context; a picture
 * of another site is "not found".
 */
class WebsiteMediaController extends Controller
{
    use PartnerConsole;

    private const WRITERS = [PartnerUserRole::Owner, PartnerUserRole::Sales];

    public function __construct(private Websites $websites, private MediaLibrary $library) {}

    public function index(): JsonResponse
    {
        $site = $this->site();
        $used = $this->library->usedIds($site);
        $rows = Media::query()->where('site_id', $site->getKey())->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json([
            'data' => $rows->map(fn (Media $media) => $this->row($media, $used))->values(),
            'meta' => [...$this->library->limits($site), 'count' => $rows->count(), 'can_write' => $this->hasRole(...self::WRITERS)],
        ]);
    }

    public function store(UploadMediaRequest $request): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $media = $this->library->store($this->site(), $request->file('file'), $request->user());

        return response()->json(['data' => $this->row($media, []), 'message' => __('marketing.messages.media_added')], 201);
    }

    public function update(UpdateMediaRequest $request, string $media): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $site = $this->site();
        $row = $this->library->update($this->library->find($site, $media), $request->validated());

        return response()->json(['data' => $this->row($row, $this->library->usedIds($site)), 'message' => __('marketing.messages.saved')]);
    }

    public function destroy(Request $request, string $media): JsonResponse
    {
        $this->requireRole(...self::WRITERS);
        $site = $this->site();
        $this->library->remove($site, $this->library->find($site, $media), $request->user());

        return response()->json(['message' => __('marketing.messages.deleted')]);
    }

    /** A picture for the console's own screens (the public gets it from the site). */
    public function file(string $media, string $size): Response
    {
        $bytes = $this->library->bytes($this->library->find($this->site(), $media), $size);

        return $bytes === null ? response('', 404) : response($bytes, 200, [
            'Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function site(): Site
    {
        return $this->websites->require($this->partner());
    }

    /**
     * @param  list<string>  $used
     * @return array<string, mixed>
     */
    private function row(Media $media, array $used): array
    {
        $base = url('/api/partner/website/media/'.$media->getKey());

        return [
            'id' => $media->getKey(), 'name' => $media->name, 'alt' => (object) ($media->alt ?? []),
            'width' => $media->width, 'height' => $media->height, 'size_bytes' => $media->size_bytes,
            'url' => "{$base}/large", 'thumb' => "{$base}/small",
            'in_use' => in_array($media->getKey(), $used, true),
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }
}
