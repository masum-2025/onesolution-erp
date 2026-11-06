<?php

namespace App\Platform\Marketing\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Marketing\Exceptions\MarketingException;
use App\Platform\Marketing\Models\Media;
use App\Platform\Marketing\Models\Site;
use App\Platform\Marketing\Models\Version;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A company website's pictures, on the private disk (never under public/),
 * served by the site itself. The kind is read from the file's bytes, not
 * its name; at most marketing.max_media_mb each and marketing.max_media_count
 * per site (rules of the platform, set per partner). A picture is drawn
 * again as two WebP copies (1600 and 480 pixels wide), which also drops
 * anything hidden in the original (camera, place); the original is not kept.
 *
 * A picture is used wherever a site's data has an "image" key naming it:
 * in its drafts, its published snapshot or a kept earlier version. A used
 * picture cannot be removed, so going back to a version never loses one.
 */
class MediaLibrary
{
    public const KEY = 'image';

    public const SIZES = ['large' => 1600, 'small' => 480];

    private const DISK = 'local';

    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    public function find(Site $site, string $id): Media
    {
        return Media::query()->where('site_id', $site->getKey())->find($id) ?? throw MarketingException::mediaNotFound();
    }

    /** The largest upload (MB) and the most pictures a site keeps. */
    public function limits(Site $site): array
    {
        $context = $this->contexts->forPartner($site->partner);

        return [
            'max_mb' => (int) $this->rules->get('marketing.max_media_mb', $context),
            'max_count' => (int) $this->rules->get('marketing.max_media_count', $context),
        ];
    }

    public function store(Site $site, UploadedFile $file, User $actor): Media
    {
        $limits = $this->limits($site);
        if ($file->getSize() > $limits['max_mb'] * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => [__('marketing.errors.media_too_big', ['mb' => $limits['max_mb']])]]);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($mime, self::TYPES, true)) {
            throw ValidationException::withMessages(['file' => [__('marketing.errors.media_kind')]]);
        }
        if (Media::query()->where('site_id', $site->getKey())->count() >= $limits['max_count']) {
            throw MarketingException::mediaFull($limits['max_count']);
        }

        $id = (string) Str::ulid();
        $folder = "marketing/{$site->getKey()}/{$id}";
        [$paths, $width, $height] = $this->pictures($file, $mime, $folder);

        $media = new Media;
        $media->forceFill(['id' => $id]);
        $media->fill([
            'site_id' => $site->getKey(),
            'name' => mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 150) ?: 'picture',
            'mime' => 'image/webp',
            'size_bytes' => Storage::disk(self::DISK)->size($paths['large']),
            'width' => $width, 'height' => $height, 'paths' => $paths, 'created_by' => $actor->getKey(),
        ])->save();
        $this->audit->record(action: 'marketing.media_added', target: $media, new: ['name' => $media->name, 'bytes' => $media->size_bytes], actor: $actor, partnerId: $site->partner_id);

        return $media;
    }

    /** @param  array{name?: string, alt?: array<string, string|null>|null}  $changes */
    public function update(Media $media, array $changes): Media
    {
        $media->fill($changes)->save();

        return $media;
    }

    public function remove(Site $site, Media $media, User $actor): void
    {
        if (in_array($media->getKey(), $this->usedIds($site), true)) {
            throw MarketingException::mediaInUse();
        }
        Storage::disk(self::DISK)->deleteDirectory(dirname((string) collect($media->paths)->first()));
        $media->delete();
        $this->audit->record(action: 'marketing.media_removed', target: $media, old: ['name' => $media->name], actor: $actor, partnerId: $site->partner_id);
    }

    /**
     * Every picture the site uses: drafts, published snapshot and kept versions.
     *
     * @return list<string>
     */
    public function usedIds(Site $site): array
    {
        $snapshots = [
            app(Websites::class)->snapshot($site),
            $site->published ?? [],
            ...Version::query()->where('site_id', $site->getKey())->pluck('snapshot')->all(),
        ];

        return array_values(array_unique(array_merge(...array_map(fn ($snapshot) => self::idsIn((array) $snapshot), $snapshots))));
    }

    /**
     * The pictures some data names (every "image" key, at any depth).
     *
     * @param  array<mixed>  $data
     * @return list<string>
     */
    public static function idsIn(array $data): array
    {
        $ids = [];
        array_walk_recursive($data, function ($value, $key) use (&$ids) {
            if ($key === self::KEY && is_string($value) && $value !== '') {
                $ids[] = $value;
            }
        });

        return array_values(array_unique($ids));
    }

    /**
     * Every picture some data names must be one of this site's.
     *
     * @param  array<mixed>  $data
     */
    public static function checkOwned(Site $site, array $data): void
    {
        $ids = self::idsIn($data);
        if ($ids !== [] && Media::query()->where('site_id', $site->getKey())->whereIn('id', $ids)->count() !== count($ids)) {
            throw MarketingException::mediaNotFound();
        }
    }

    /** The stored copy of a size ("large" or "small"). */
    public function path(Media $media, string $size): ?string
    {
        return $media->paths[$size] ?? $media->paths['large'] ?? null;
    }

    /** The file's bytes, or null when it is gone. */
    public function bytes(Media $media, string $size): ?string
    {
        $path = $this->path($media, $size);

        return $path !== null && Storage::disk(self::DISK)->exists($path) ? Storage::disk(self::DISK)->get($path) : null;
    }

    /**
     * @return array{0: array<string, string>, 1: int, 2: int}
     */
    private function pictures(UploadedFile $file, string $mime, string $folder): array
    {
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),
            'image/png' => @imagecreatefrompng($file->getRealPath()),
            default => @imagecreatefromwebp($file->getRealPath()),
        };
        if ($source === false) {
            throw ValidationException::withMessages(['file' => [__('marketing.errors.media_kind')]]);
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $paths = [];
        foreach (self::SIZES as $name => $maxWidth) {
            $targetWidth = min($width, $maxWidth);
            $targetHeight = max(1, intdiv($height * $targetWidth, max(1, $width)));
            $copy = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($copy, false);
            imagesavealpha($copy, true);
            imagecopyresampled($copy, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            ob_start();
            imagewebp($copy, null, 80);
            $bytes = (string) ob_get_clean();
            imagedestroy($copy);
            $path = "{$folder}/{$name}.webp";
            Storage::disk(self::DISK)->put($path, $bytes);
            $paths[$name] = $path;
        }
        imagedestroy($source);
        $large = min($width, self::SIZES['large']);

        return [$paths, $large, max(1, intdiv($height * $large, max(1, $width)))];
    }
}
