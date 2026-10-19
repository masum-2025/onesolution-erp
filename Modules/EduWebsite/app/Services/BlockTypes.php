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
    public const TYPES = ['hero', 'text', 'notices', 'admission', 'programs', 'people', 'gallery', 'downloads', 'contact', 'attendance'];

    /** Validation rules for a block's data. */
    public static function rules(string $type): array
    {
        $title = self::text('title', 120, false);

        return match ($type) {
            'hero' => [...self::text('title', 120, true), ...self::text('text', 300, false), 'image_media_id' => ['nullable', 'string', 'size:26'],
                ...self::text('button_label', 40, false), 'button_url' => self::link()],
            'text' => [...$title, ...self::text('body', 5000, true)],
            'notices' => [...$title, 'count' => ['required', 'integer', 'min:1', 'max:20']],
            'admission' => [...$title, ...self::text('body', 3000, true), 'deadline' => ['nullable', 'date_format:Y-m-d'],
                ...self::text('button_label', 40, false), 'button_url' => self::link()],
            'programs' => [...$title, 'source' => ['required', 'in:education,manual'], 'items' => ['array', 'max:40', 'required_if:source,manual'],
                ...self::text('items.*.name', 100, true), ...self::text('items.*.text', 300, false)],
            'people' => [...$title, 'items' => ['required', 'array', 'min:1', 'max:60'], 'items.*.name' => ['required', 'string', 'max:100'],
                ...self::text('items.*.role', 100, false), 'items.*.image_media_id' => ['nullable', 'string', 'size:26']],
            'gallery' => [...$title, 'media_ids' => ['required', 'array', 'min:1', 'max:40'], 'media_ids.*' => ['string', 'size:26']],
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
    public static function clean(string $type, array $data): array
    {
        $validator = Validator::make($data, self::rules($type));
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
            ...array_map(fn ($item) => $item['image_media_id'] ?? null, $type === 'people' ? ($data['items'] ?? []) : []),
        ]));
    }

    /**
     * A text in every language: an object of the supported languages, the
     * default one required when the text is.
     *
     * @return array<string, mixed>
     */
    private static function text(string $field, int $max, bool $required): array
    {
        $codes = LanguageRegistry::codes();

        return [
            $field => [$required ? 'required' : 'nullable', 'array:'.implode(',', $codes)],
            "{$field}.en" => [$required ? 'required' : 'nullable', 'string', 'max:'.$max],
            ...collect($codes)->reject(fn ($code) => $code === 'en')->mapWithKeys(fn ($code) => ["{$field}.{$code}" => ['nullable', 'string', 'max:'.$max]])->all(),
        ];
    }

    /** @return list<string> */
    private static function link(): array
    {
        return ['nullable', 'string', 'max:300', 'regex:#^(https?://[^\s<>"]+|/[^\s<>"]*)$#i'];
    }
}
