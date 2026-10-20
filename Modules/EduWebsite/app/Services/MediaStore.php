<?php

namespace Modules\EduWebsite\Services;

use App\Models\User;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Models\Block;
use Modules\EduWebsite\Models\Media;
use Modules\EduWebsite\Models\Notice;
use Modules\EduWebsite\Models\Page;
use Modules\EduWebsite\Models\Site;

/**
 * Pictures and PDFs of a website, on the private disk (never under
 * public/), served by the site's own route. The kind is read from the
 * file's bytes, not its name; at most edu_website.max_media_mb. A picture
 * is drawn again as two WebP copies (1600 and 480 pixels wide), which also
 * drops anything hidden in the original (camera, place); the original is
 * not kept. A file in use (logo, block, notice) cannot be removed.
 */
class MediaStore
{
    private const DISK = 'local';

    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const SIZES = ['large' => 1600, 'small' => 480];

    public function __construct(private Place $place, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function store(Organization $company, Site $site, UploadedFile $file, User $actor): Media
    {
        $maxMb = (int) $this->rules->get('edu_website.max_media_mb', $this->contexts->forOrganization($company));
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => __('edu_website::website.validation.too_big', ['mb' => $maxMb])]);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $kind = in_array($mime, self::IMAGE_TYPES, true) ? 'image' : ($mime === 'application/pdf' ? 'pdf' : null);
        if ($kind === null) {
            throw ValidationException::withMessages(['file' => __('edu_website::website.validation.file_kind')]);
        }

        $id = (string) Str::ulid();
        $folder = "edu_website/{$company->getKey()}/{$id}";
        [$paths, $width, $height] = $kind === 'image' ? $this->pictures($file, $mime, $folder) : [['file' => $file->storeAs($folder, 'file.pdf', self::DISK)], null, null];

        $media = new Media;
        $media->forceFill(['id' => $id]);
        $media->fill([
            'organization_id' => $company->getKey(), 'site_id' => $site->getKey(), 'kind' => $kind,
            'name' => mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 150) ?: $kind,
            'mime' => $kind === 'image' ? 'image/webp' : 'application/pdf', 'size_bytes' => $kind === 'image' ? Storage::disk(self::DISK)->size($paths['large']) : $file->getSize(),
            'width' => $width, 'height' => $height, 'paths' => $paths, 'created_by' => $actor->getKey(),
        ])->save();

        return $media;
    }

    public function remove(Organization $company, Media $media): void
    {
        if ($this->inUse($company, $media)) {
            throw WebsiteException::mediaInUse();
        }
        Storage::disk(self::DISK)->deleteDirectory(dirname((string) collect($media->paths)->first()));
        $media->delete();
    }

    /** The stored file of a size ("large", "small"; a PDF has one). */
    public function path(Media $media, string $size): ?string
    {
        return $media->kind === 'pdf' ? ($media->paths['file'] ?? null) : ($media->paths[$size] ?? $media->paths['large'] ?? null);
    }

    public function disk(): string
    {
        return self::DISK;
    }

    private function inUse(Organization $company, Media $media): bool
    {
        return in_array($media->getKey(), $this->usedIds($company), true);
    }

    /**
     * Pictures and files the website uses: its logo and sharing pictures, notices, blocks.
     *
     * @return list<string>
     */
    public function usedIds(Organization $company): array
    {
        return array_values(array_unique(array_filter([
            ...$this->place->query(Site::class, $company)->pluck('logo_media_id')->all(),
            ...$this->place->query(Site::class, $company)->pluck('share_media_id')->all(),
            ...$this->place->query(Page::class, $company)->pluck('share_media_id')->all(),
            ...$this->place->query(Notice::class, $company)->pluck('media_id')->all(),
            ...$this->place->query(Block::class, $company)->get(['type', 'data'])
                ->flatMap(fn (Block $block) => BlockTypes::mediaIds($block->type, $block->data ?? []))->all(),
        ])));
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
            throw ValidationException::withMessages(['file' => __('edu_website::website.validation.file_kind')]);
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

        return [$paths, min($width, self::SIZES['large']), max(1, intdiv($height * min($width, self::SIZES['large']), max(1, $width)))];
    }
}
