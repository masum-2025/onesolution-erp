<?php

namespace App\Platform\Marketing\Services;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\PlanCatalog;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The kinds of section a company website page is built from, and what each
 * may hold. Texts are plain text per language ({en, bn}; never HTML, the
 * page escapes them), links are https addresses, mail/phone links or the
 * site's own paths, colours and icons come from fixed lists (so no value can
 * reach the stylesheet). Only the listed fields are kept.
 */
final class BlockTypes
{
    public const TYPES = ['hero_slider', 'stats', 'ticker', 'offer', 'cards', 'product', 'features', 'partner', 'pricing', 'gallery', 'news', 'faq', 'cta', 'text'];

    /** Slider effects, product section styles, and the small drawn pictures a slide or product card can show. */
    public const EFFECTS = ['fade', 'slide', 'zoom'];

    public const PRODUCT_STYLES = ['bento', 'orbit', 'simple'];

    public const SLIDE_VISUALS = ['dashboard', 'report_card', 'brands'];

    public const CARD_VISUALS = ['punch', 'line', 'grade', 'ring', 'pipe', 'sync', 'none'];

    public const COLORS = ['blue', 'green', 'red', 'violet', 'sky', 'amber', 'navy'];

    public const ICONS = ['school', 'university', 'mosque', 'factory', 'shop', 'briefcase', 'buildings', 'person', 'bolt', 'phone', 'lock', 'landmark', 'globe', 'chart', 'users', 'box', 'heart', 'star', 'chat', 'gear'];

    public function __construct(private ModuleRegistry $modules, private PlanCatalog $plans) {}

    /**
     * Validation rules for a block's data.
     *
     * @return array<string, mixed>
     */
    public function rules(string $type, string $main): array
    {
        $head = [...$this->text($main, 'eyebrow', 60), ...$this->text($main, 'title', 160), ...$this->text($main, 'intro', 400)];
        $button = fn (string $name) => [...$this->text($main, "{$name}_label", 40), "{$name}_url" => $this->link()];

        return match ($type) {
            // Slides that turn by themselves (or not), with one of three effects.
            'hero_slider' => [
                'effect' => ['required', Rule::in(self::EFFECTS)],
                'seconds' => ['required', 'integer', 'min:3', 'max:15'],
                'autoplay' => ['required', 'boolean'],
                'slides' => ['required', 'array', 'list', 'min:1', 'max:6'],
                ...$this->text($main, 'slides.*.eyebrow', 60),
                ...$this->text($main, 'slides.*.title', 120, true),
                // The coloured end of the title; or words that take turns there.
                ...$this->text($main, 'slides.*.highlight', 60),
                'slides.*.rotating' => ['nullable', 'array', 'list', 'max:6'],
                ...$this->text($main, 'slides.*.rotating.*', 30, true),
                ...$this->text($main, 'slides.*.text', 300),
                ...$this->prefixed('slides.*.', $button('primary')),
                ...$this->prefixed('slides.*.', $button('secondary')),
                'slides.*.ticks' => ['nullable', 'array', 'list', 'max:4'],
                ...$this->text($main, 'slides.*.ticks.*', 40, true),
                'slides.*.visual' => ['required', Rule::in(self::SLIDE_VISUALS)],
            ],
            // A few numbers (counted up as they come into view) or a word.
            'stats' => [
                'items' => ['required', 'array', 'list', 'min:1', 'max:6'],
                'items.*.value' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
                // "modules" counts the platform's modules; "sectors" the sector packages.
                'items.*.auto' => ['nullable', Rule::in(['modules', 'sectors', 'languages'])],
                'items.*.suffix' => ['nullable', 'string', 'max:4'],
                ...$this->text($main, 'items.*.word', 20),
                ...$this->text($main, 'items.*.label', 60, true),
            ],
            // A strip of names that moves by itself: the modules, or written by hand.
            'ticker' => [
                'source' => ['required', Rule::in(['modules', 'manual'])],
                'items' => ['nullable', 'array', 'list', 'max:30', 'required_if:source,manual'],
                ...$this->text($main, 'items.*', 40, true),
            ],
            // An offer with an end time (a countdown) and a button.
            'offer' => [
                ...$this->text($main, 'tag', 30), ...$this->text($main, 'title', 140, true), ...$this->text($main, 'text', 240),
                'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
                ...$button('button'),
            ],
            // Cards with an icon: sectors, solutions, services.
            'cards' => [
                ...$head,
                'items' => ['required', 'array', 'list', 'min:1', 'max:12'],
                'items.*.icon' => ['required', Rule::in(self::ICONS)],
                'items.*.color' => ['required', Rule::in(self::COLORS)],
                ...$this->text($main, 'items.*.title', 60, true), ...$this->text($main, 'items.*.text', 200),
                'items.*.url' => $this->link(),
            ],
            // The modules, grouped: bento cards, an orbit, or simple lists.
            'product' => [
                ...$head,
                'style' => ['required', Rule::in(self::PRODUCT_STYLES)],
                ...$this->text($main, 'orbit_title', 120), ...$this->text($main, 'orbit_text', 400),
                'groups' => ['required', 'array', 'list', 'min:1', 'max:8'],
                ...$this->text($main, 'groups.*.name', 40, true), ...$this->text($main, 'groups.*.title', 100), ...$this->text($main, 'groups.*.text', 200),
                'groups.*.color' => ['required', Rule::in(self::COLORS)],
                'groups.*.visual' => ['required', Rule::in(self::CARD_VISUALS)],
                'groups.*.modules' => ['required', 'array', 'list', 'min:1', 'max:12'],
                'groups.*.modules.*' => ['required', 'string', Rule::in($this->modules->keys())],
                'groups.*.url' => $this->link(),
            ],
            // A list of strong points beside a panel of rows.
            'features' => [
                ...$head,
                'items' => ['required', 'array', 'list', 'min:1', 'max:6'],
                'items.*.icon' => ['required', Rule::in(self::ICONS)],
                'items.*.color' => ['required', Rule::in(self::COLORS)],
                ...$this->text($main, 'items.*.title', 60, true), ...$this->text($main, 'items.*.text', 240),
                ...$this->text($main, 'panel_title', 60),
                'panel' => ['nullable', 'array', 'list', 'max:6'],
                ...$this->text($main, 'panel.*.label', 40, true), ...$this->text($main, 'panel.*.value', 60),
            ],
            // A strong band with numbered steps (the partner programme).
            'partner' => [
                ...$head,
                ...$this->text($main, 'text', 400),
                ...$button('button'),
                'steps' => ['nullable', 'array', 'list', 'max:4'],
                ...$this->text($main, 'steps.*', 80, true),
            ],
            // Plans and prices from the plan catalogue (never typed here).
            'pricing' => [
                ...$head,
                'plans' => ['required', 'array', 'list', 'min:1', 'max:5'],
                'plans.*' => ['required', 'string', Rule::in($this->plans->keys())],
                'highlight' => ['nullable', 'string', Rule::in($this->plans->keys())],
                'currency' => ['required', Rule::in(['BDT', 'USD'])],
                ...$this->text($main, 'note', 200),
                'features' => ['nullable', 'array'],
                'features.*' => ['array', 'list', 'max:6'],
                ...$this->text($main, 'features.*.*', 50, true),
            ],
            // Pictures with a caption (until pictures can be uploaded: coloured tiles).
            'gallery' => [
                ...$head,
                'items' => ['required', 'array', 'list', 'min:1', 'max:12'],
                ...$this->text($main, 'items.*.caption', 80, true),
                'items.*.color' => ['required', Rule::in(self::COLORS)],
            ],
            // News and notices written here: a date, a title, a line, a link.
            'news' => [
                ...$head,
                'items' => ['required', 'array', 'list', 'min:1', 'max:9'],
                'items.*.date' => ['required', 'date_format:Y-m-d'],
                ...$this->text($main, 'items.*.title', 100, true), ...$this->text($main, 'items.*.text', 240),
                'items.*.url' => $this->link(),
            ],
            // Questions and answers (also given to search engines as an FAQ).
            'faq' => [
                ...$head,
                'items' => ['required', 'array', 'list', 'min:1', 'max:20'],
                ...$this->text($main, 'items.*.question', 160, true), ...$this->text($main, 'items.*.answer', 1000, true),
            ],
            // The closing call to act.
            'cta' => [...$this->text($main, 'title', 120, true), ...$this->text($main, 'text', 240), ...$button('primary'), ...$button('secondary')],
            // Plain text in paragraphs (blank lines).
            'text' => [...$head, ...$this->text($main, 'body', 8000, true)],
            default => [],
        };
    }

    /**
     * Checked data with only the known fields (a ValidationException's keys start with "data.").
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function clean(string $type, array $data, string $main): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => [__('marketing.errors.unknown_block')]]);
        }
        $validator = Validator::make($data, $this->rules($type, $main));
        if ($validator->fails()) {
            throw ValidationException::withMessages(
                collect($validator->errors()->toArray())->mapWithKeys(fn ($messages, $key) => ["data.{$key}" => $messages])->all()
            );
        }

        return $validator->validated();
    }

    /**
     * A text in every language: the site's main one required when the text is.
     *
     * @return array<string, mixed>
     */
    private function text(string $main, string $field, int $max, bool $required = false): array
    {
        $codes = LanguageRegistry::codes();

        return [
            $field => [$required ? 'required' : 'nullable', 'array:'.implode(',', $codes)],
            "{$field}.{$main}" => [$required ? 'required' : 'nullable', 'string', 'max:'.$max],
            ...collect($codes)->reject(fn ($code) => $code === $main)->mapWithKeys(fn ($code) => ["{$field}.{$code}" => ['nullable', 'string', 'max:'.$max]])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function prefixed(string $prefix, array $rules): array
    {
        return collect($rules)->mapWithKeys(fn ($rule, $key) => [$prefix.$key => $rule])->all();
    }

    /**
     * https, mail and phone links, the site's own paths and anchors, or "app:/path": a page of the
     * app itself (sign in, sign up) at the partner's own app address.
     *
     * @return list<string>
     */
    public static function link(): array
    {
        return ['nullable', 'string', 'max:300', 'regex:#^(https://[^\s<>"]+|mailto:[^\s<>"]+|tel:[0-9+() -]+|app:/[^\s<>"]*|/[^\s<>"]*|\#[A-Za-z0-9_-]+)$#i'];
    }
}
