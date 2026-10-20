<?php

namespace Modules\EduWebsite\Services;

use App\Platform\Localization\LanguageRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The kinds of block a page is built from, and what each may hold. Texts
 * are plain text per language ({en, bn}, never HTML: the page escapes
 * them); links are http(s) addresses or the site's own paths; pictures
 * and files are the site's media. Only the listed fields are kept.
 */
final class BlockTypes
{
    public const TYPES = ['hero', 'slider', 'text', 'notices', 'admission', 'programs', 'people', 'gallery', 'stats', 'downloads', 'contact', 'attendance'];

    /** Validation rules for a block's data. */
    public static function rules(string $type, string $main = 'en'): array
    {
        $title = self::text($main, 'title', 120, false);

        return match ($type) {
            'hero' => [...self::text($main, 'title', 120, true), ...self::text($main, 'text', 300, false), 'image_media_id' => ['nullable', 'string', 'size:26'],
                ...self::text($main, 'button_label', 40, false), 'button_url' => self::link()],
            'text' => [...$title, ...self::text($main, 'body', 5000, true)],
            'notices' => [...$title, 'count' => ['required', 'integer', 'min:1', 'max:20']],
            'admission' => [...$title, ...self::text($main, 'body', 3000, true), 'deadline' => ['nullable', 'date_format:Y-m-d'],
                ...self::text($main, 'button_label', 40, false), 'button_url' => self::link()],
            'programs' => [...$title, 'source' => ['required', 'in:education,manual'], 'items' => ['array', 'max:40', 'required_if:source,manual'],
                ...self::text($main, 'items.*.name', 100, true), ...self::text($main, 'items.*.text', 300, false)],
            'people' => [...$title, 'items' => ['required', 'array', 'min:1', 'max:60'], 'items.*.name' => ['required', 'string', 'max:100'],
                ...self::text($main, 'items.*.role', 100, false), 'items.*.image_media_id' => ['nullable', 'string', 'size:26']],
            // Slides of pictures with a title, a line and a button; turning by themselves or not.
            'slider' => ['slides' => ['required', 'array', 'min:1', 'max:8'], 'slides.*.image_media_id' => ['required', 'string', 'size:26'],
                ...self::text($main, 'slides.*.title', 120, false), ...self::text($main, 'slides.*.text', 240, false),
                ...self::text($main, 'slides.*.button_label', 40, false), 'slides.*.button_url' => self::link(),
                'autoplay' => ['boolean'], 'interval' => ['nullable', 'integer', 'min:3', 'max:10'],
                'height' => ['nullable', 'in:normal,full'], 'effect' => ['nullable', 'in:fade,slide']],
            // Pictures with a caption each (items), or the ids alone as before (media_ids); laid out as a grid, masonry or a strip.
            'gallery' => [...$title, 'layout' => ['nullable', 'in:grid,masonry,carousel'],
                'items' => ['required_without:media_ids', 'array', 'min:1', 'max:40'], 'items.*.image_media_id' => ['required', 'string', 'size:26'],
                ...self::text($main, 'items.*.caption', 150, false),
                'media_ids' => ['required_without:items', 'array', 'min:1', 'max:40'], 'media_ids.*' => ['string', 'size:26']],
            // A few numbers the school is proud of (written by hand): "1200+ students".
            'stats' => [...$title, 'items' => ['required', 'array', 'min:1', 'max:6'],
                'items.*.value' => ['required', 'integer', 'min:0', 'max:1000000000'], 'items.*.suffix' => ['nullable', 'string', 'max:4'],
                ...self::text($main, 'items.*.label', 60, true)],
            'downloads' => [...$title, 'media_ids' => ['required', 'array', 'min:1', 'max:30'], 'media_ids.*' => ['string', 'size:26']],
            'contact' => [...$title, 'show_map' => ['boolean']],
            'attendance' => [...$title, 'students' => ['boolean'], 'staff' => ['boolean']],
            default => [],
        };
    }

    /**
     * Checked data with only the known fields (throws a ValidationException
     * whose keys start with "data.").
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function clean(string $type, array $data, string $main = 'en'): array
    {
        $validator = Validator::make($data, self::rules($type, $main));
        if ($validator->fails()) {
            throw ValidationException::withMessages(
                collect($validator->errors()->toArray())->mapWithKeys(fn ($messages, $key) => ["data.{$key}" => $messages])->all()
            );
        }

        return $validator->validated();
    }

    /** Media a block uses (to keep them from being removed while in use). */
    public static function mediaIds(string $type, array $data): array
    {
        return array_values(array_filter([
            $data['image_media_id'] ?? null,
            ...($data['media_ids'] ?? []),
            ...array_map(fn ($item) => $item['image_media_id'] ?? null, in_array($type, ['people', 'gallery'], true) ? ($data['items'] ?? []) : []),
            ...array_map(fn ($slide) => $slide['image_media_id'] ?? null, $type === 'slider' ? ($data['slides'] ?? []) : []),
        ]));
    }

    /**
     * A text in every language: an object of the supported languages, the
     * site's main one required when the text is.
     *
     * @return array<string, mixed>
     */
    private static function text(string $main, string $field, int $max, bool $required): array
    {
        $codes = LanguageRegistry::codes();

        return [
            $field => [$required ? 'required' : 'nullable', 'array:'.implode(',', $codes)],
            "{$field}.{$main}" => [$required ? 'required' : 'nullable', 'string', 'max:'.$max],
            ...collect($codes)->reject(fn ($code) => $code === $main)->mapWithKeys(fn ($code) => ["{$field}.{$code}" => ['nullable', 'string', 'max:'.$max]])->all(),
        ];
    }

    /** @return list<string> */
    private static function link(): array
    {
        return ['nullable', 'string', 'max:300', 'regex:#^(https?://[^\s<>"]+|/[^\s<>"]*)$#i'];
    }
}
