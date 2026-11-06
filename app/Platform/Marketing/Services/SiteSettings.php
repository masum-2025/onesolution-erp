<?php

namespace App\Platform\Marketing\Services;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Marketing\Models\Site;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A website's own settings, around its pages: colours (the brand's when
 * empty), the header menu and buttons, the notice bar, contact details,
 * social links, the footer and the site-wide search details. Texts are per
 * language and plain; links as BlockTypes::link; colours #RRGGBB only.
 */
final class SiteSettings
{
    private const COLOR = 'regex:/^#[0-9A-Fa-f]{6}$/';

    /** Social networks a footer can link to (https only). */
    public const SOCIAL = ['facebook', 'linkedin', 'youtube', 'x', 'instagram'];

    /** @return array<string, mixed> */
    public function rules(string $main): array
    {
        $link = BlockTypes::link();

        return [
            'colors' => ['nullable', 'array:primary,accent,highlight'],
            'colors.primary' => ['nullable', 'string', self::COLOR],
            'colors.accent' => ['nullable', 'string', self::COLOR],
            'colors.highlight' => ['nullable', 'string', self::COLOR],

            'header' => ['nullable', 'array:menu,cta_label,cta_url,signin_label,signin_url'],
            'header.menu' => ['nullable', 'array', 'list', 'max:8'],
            ...$this->text($main, 'header.menu.*.label', 30, true),
            'header.menu.*.url' => ['required', ...array_slice($link, 1)],
            ...$this->text($main, 'header.cta_label', 30), 'header.cta_url' => $link,
            ...$this->text($main, 'header.signin_label', 30), 'header.signin_url' => $link,

            // The thin bar above the header: a tag, a line, a link, a second line (e.g. an offer); between two days.
            'notice' => ['nullable', 'array:enabled,tag,text,link_label,url,extra,from,until'],
            'notice.enabled' => ['nullable', 'boolean'],
            ...$this->text($main, 'notice.tag', 16), ...$this->text($main, 'notice.text', 140), ...$this->text($main, 'notice.link_label', 40),
            'notice.url' => $link, ...$this->text($main, 'notice.extra', 140),
            'notice.from' => ['nullable', 'date_format:Y-m-d'],
            'notice.until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:notice.from'],

            'contact' => ['nullable', 'array:phone,email,address'],
            'contact.phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()\-]{5,30}$/'],
            'contact.email' => ['nullable', 'string', 'email', 'max:255'],
            ...$this->text($main, 'contact.address', 200),

            'social' => ['nullable', 'array:'.implode(',', self::SOCIAL)],
            ...collect(self::SOCIAL)->mapWithKeys(fn ($network) => ["social.{$network}" => ['nullable', 'string', 'url:https', 'max:300']])->all(),

            'footer' => ['nullable', 'array:about,columns,copyright'],
            ...$this->text($main, 'footer.about', 300),
            'footer.columns' => ['nullable', 'array', 'list', 'max:4'],
            ...$this->text($main, 'footer.columns.*.title', 40, true),
            'footer.columns.*.links' => ['required', 'array', 'list', 'max:8'],
            ...$this->text($main, 'footer.columns.*.links.*.label', 40, true),
            'footer.columns.*.links.*.url' => ['required', ...array_slice($link, 1)],
            ...$this->text($main, 'footer.copyright', 120),

            'seo' => ['nullable', 'array:title,description,image,image_url,google_verification,bing_verification'],
            ...$this->text($main, 'seo.title', 70), ...$this->text($main, 'seo.description', 170),
            // The picture shown when the site is shared: one of its own (wins), or an address.
            'seo.image' => BlockTypes::media(),
            'seo.image_url' => ['nullable', 'string', 'max:300', 'regex:#^(https://[^\s<>"]+|/[^\s<>"]*)$#i'],
            'seo.google_verification' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{10,100}$/'],
            'seo.bing_verification' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{10,64}$/'],
        ];
    }

    /**
     * Checked settings with only the known fields.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function clean(array $settings, string $main): array
    {
        $validator = Validator::make($settings, $this->rules($main));
        if ($validator->fails()) {
            throw ValidationException::withMessages(
                collect($validator->errors()->toArray())->mapWithKeys(fn ($messages, $key) => ["settings.{$key}" => $messages])->all()
            );
        }

        return $validator->validated();
    }

    /** Rules for the template and languages of a site. @return array<string, mixed> */
    public static function siteRules(): array
    {
        return [
            'template' => ['sometimes', 'string', Rule::in(Site::TEMPLATES)],
            'locales' => ['sometimes', 'array', 'list', 'min:1', 'max:'.count(LanguageRegistry::codes())],
            'locales.*' => ['required', 'string', 'distinct', Rule::in(LanguageRegistry::codes())],
            'default_locale' => ['sometimes', 'string', Rule::in(LanguageRegistry::codes())],
        ];
    }

    /** @return array<string, mixed> */
    private function text(string $main, string $field, int $max, bool $required = false): array
    {
        $codes = LanguageRegistry::codes();

        return [
            $field => [$required ? 'required' : 'nullable', 'array:'.implode(',', $codes)],
            "{$field}.{$main}" => [$required ? 'required' : 'nullable', 'string', 'max:'.$max],
            ...collect($codes)->reject(fn ($code) => $code === $main)->mapWithKeys(fn ($code) => ["{$field}.{$code}" => ['nullable', 'string', 'max:'.$max]])->all(),
        ];
    }
}
