<?php

namespace App\Platform\Marketing\Services;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
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
    public const TYPES = ['hero_slider', 'stats', 'ticker', 'offer', 'cards', 'product', 'features', 'partner', 'pricing', 'gallery', 'news', 'faq', 'cta', 'text', 'contact_form'];

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
    public function rules(string $type, string $main, ?Partner $partner = null): array
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
                // A picture of the site's own instead of the drawn one.
                'slides.*.image' => self::media(),
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
            // Plans and prices never typed here: the platform's plan catalogue on its own site, a partner's own active plans on its site.
            'pricing' => [
                ...$head,
                'plans' => ['required', 'array', 'list', 'min:1', 'max:5'],
                'plans.*' => ['required', 'string', Rule::in($this->planKeys($partner))],
                'highlight' => ['nullable', 'string', Rule::in($this->planKeys($partner))],
                'currency' => ['required', Rule::in(['BDT', 'USD'])],
                ...$this->text($main, 'note', 200),
                'features' => ['nullable', 'array'],
                'features.*' => ['array', 'list', 'max:6'],
                ...$this->text($main, 'features.*.*', 50, true),
            ],
            // Pictures with a caption (without a picture: a coloured tile).
            'gallery' => [
                ...$head,
                'items' => ['required', 'array', 'list', 'min:1', 'max:12'],
                ...$this->text($main, 'items.*.caption', 80, true),
                'items.*.image' => self::media(),
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
            'text' => [...$head, ...$this->text($main, 'body', 8000, true), 'image' => self::media()],
            // A form visitors write through (name and phone always; the rest when asked), beside the
            // site's phone, email and address. Messages go to the console's Messages (Leads).
            'contact_form' => [
                ...$head,
                'ask_email' => ['required', 'boolean'],
                'ask_company' => ['required', 'boolean'],
                'ask_message' => ['required', 'boolean'],
                ...$this->text($main, 'button_label', 40),
                ...$this->text($main, 'thanks', 200),
                'show_contact' => ['required', 'boolean'],
            ],
            default => [],
        };
    }

    /**
     * Checked data with only the known fields (a ValidationException's keys start with "data.").
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function clean(string $type, array $data, string $main, ?Partner $partner = null): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => [__('marketing.errors.unknown_block')]]);
        }
        $validator = Validator::make($data, $this->rules($type, $main, $partner));
        if ($validator->fails()) {
            throw ValidationException::withMessages(
                collect($validator->errors()->toArray())->mapWithKeys(fn ($messages, $key) => ["data.{$key}" => $messages])->all()
            );
        }

        return $validator->validated();
    }

    /**
     * What the console's form shows for each kind of section, read from the
     * same rules that check it (one place for both): every field with its
     * kind (text, list, map, choice, integer, boolean, date, datetime, link,
     * string), whether it is required, its limits and its options.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    /**
     * The plans a pricing section may name: the catalogue's keys on the
     * platform's own site, the partner's own active plans (their ids) on a
     * partner's site, never another partner's.
     *
     * @return list<string>
     */
    public function planKeys(?Partner $partner): array
    {
        if ($partner === null || $partner->is_house) {
            return $this->plans->keys();
        }

        return PartnerPlan::query()->where('partner_id', $partner->getKey())->where('status', PartnerPlan::ACTIVE)
            ->orderBy('created_at')->pluck('id')->all();
    }

    public function fields(string $main, ?Partner $partner = null): array
    {
        return collect(self::TYPES)->mapWithKeys(fn (string $type) => [$type => $this->fieldsUnder($this->rules($type, $main, $partner), '')])->all();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<array<string, mixed>>
     */
    private function fieldsUnder(array $rules, string $prefix): array
    {
        $fields = [];
        foreach (array_keys($rules) as $key) {
            if (! str_starts_with($key, $prefix) || str_contains(substr($key, strlen($prefix)), '.')) {
                continue;
            }
            $fields[] = ['key' => substr($key, strlen($prefix)), ...$this->field($rules, $key)];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function field(array $rules, string $key): array
    {
        $rule = $rules[$key];
        $strings = array_values(array_filter($rule, 'is_string'));
        $limit = fn (array $list, string $name) => ($found = collect($list)->first(fn ($item) => str_starts_with($item, "{$name}:"))) === null ? null : (int) substr($found, strlen($name) + 1);
        $field = ['required' => in_array('required', $strings, true)];

        if (in_array('array:'.implode(',', LanguageRegistry::codes()), $strings, true)) {
            $locale = collect(array_keys($rules))->first(fn ($child) => str_starts_with($child, "{$key}.") && ! str_contains(substr($child, strlen($key) + 1), '.'));
            $max = $locale === null ? null : $limit(array_filter($rules[$locale], 'is_string'), 'max');

            return [...$field, 'kind' => 'text', 'max' => $max, 'multiline' => $max !== null && $max > 160];
        }
        if (in_array('list', $strings, true) || in_array('array', $strings, true)) {
            $item = isset($rules["{$key}.*"]) ? $this->field($rules, "{$key}.*") : ['kind' => 'object', 'fields' => $this->fieldsUnder($rules, "{$key}.*.")];

            return [...$field, 'kind' => in_array('list', $strings, true) ? 'list' : 'map', 'min' => $limit($strings, 'min'), 'max' => $limit($strings, 'max'), 'item' => $item];
        }
        $in = collect($rule)->first(fn ($item) => $item instanceof In);
        if ($in !== null) {
            return [...$field, 'kind' => 'choice', 'options' => array_values((fn () => $this->values)->call($in))];
        }
        $kind = match (true) {
            in_array('boolean', $strings, true) => 'boolean',
            in_array('integer', $strings, true) => 'integer',
            in_array('date_format:Y-m-d', $strings, true) => 'date',
            in_array('date_format:Y-m-d\TH:i', $strings, true) => 'datetime',
            $strings === self::link() => 'link',
            $strings === self::media() => 'media',
            default => 'string',
        };

        return [...$field, 'kind' => $kind, ...array_filter(['min' => $limit($strings, 'min'), 'max' => $limit($strings, 'max')], fn ($value) => $value !== null)];
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

    /**
     * One of the site's pictures (MediaLibrary): always under an "image" key, which is how
     * the library finds where a picture is used; whose it is is checked on saving.
     *
     * @return list<string>
     */
    public static function media(): array
    {
        return ['nullable', 'string', 'ulid'];
    }
}
