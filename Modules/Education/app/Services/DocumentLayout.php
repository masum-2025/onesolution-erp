<?php

namespace Modules\Education\Services;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Education\Models\DocumentAsset;
use Modules\Education\Models\Field;

/**
 * What a design may hold, checked the same way for every institution.
 *
 * - The page: an ID card (85.6 x 54 mm), A4 or A5, portrait or landscape.
 * - Items inside the page: text (with {placeholders}), an image, the
 *   student's photo, the QR code, a line or a box. Whole numbers only (no
 *   floats anywhere): places and sizes in tenths of a millimetre (85.6 mm is
 *   856), text sizes in tenths of a point, line height in percent.
 * - Placeholders: the student, their guardians and class, the institution,
 *   the document itself, own fields printed on documents ({field.key}) and
 *   what is asked when issuing ({input.key}). An unknown one is refused, so a
 *   typo never prints as "{studnet.name}".
 */
class DocumentLayout
{
    /** Page sizes in tenths of a millimetre (width, height) when portrait. */
    public const SIZES = ['id_card' => [540, 856], 'a4' => [2100, 2970], 'a5' => [1480, 2100]];

    public const ELEMENTS = ['text', 'image', 'photo', 'qr', 'line', 'box'];

    public const MAX_ELEMENTS = 80;

    public const MAX_INPUTS = 10;

    /** Placeholders every design may use (the values come from DocumentValues). */
    public const PLACEHOLDERS = [
        'student.name', 'student.name_local', 'student.code', 'student.admission_no', 'student.gender', 'student.phone',
        'student.date_of_birth', 'student.birth_registration_no', 'student.admitted_on', 'student.left_on', 'student.left_reason', 'student.status',
        'guardian.father', 'guardian.mother', 'guardian.primary', 'guardian.primary_phone',
        'program', 'level', 'section', 'roll', 'session', 'batch', 'category', 'shift', 'medium', 'stream',
        'institution.name',
        'document.number', 'document.date', 'document.valid_until',
    ];

    /** Placeholders that print private details. */
    public const SENSITIVE = ['student.date_of_birth', 'student.birth_registration_no'];

    public function __construct(private Fields $fields) {}

    /**
     * Page size in tenths of a millimetre, [width, height].
     *
     * @param  array{size: string, orientation: string}  $page
     * @return array{0: int, 1: int}
     */
    public static function dimensions(array $page): array
    {
        [$width, $height] = self::SIZES[$page['size']] ?? self::SIZES['a4'];

        return ($page['orientation'] ?? 'portrait') === 'landscape' ? [$height, $width] : [$width, $height];
    }

    /**
     * Checks a design and gives it back cleaned (only known keys, whole numbers).
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $layout
     * @param  list<array<string, mixed>>  $inputs
     * @return array{page: array<string, string>, layout: array<string, mixed>, inputs: list<array<string, mixed>>}
     */
    public function check(Organization $company, array $page, array $layout, array $inputs): array
    {
        $errors = [];
        if (! isset(self::SIZES[$page['size'] ?? null])) {
            $errors['page.size'] = __('education::education.validation.document_page');
        }
        if (! in_array($page['orientation'] ?? null, ['portrait', 'landscape'], true)) {
            $errors['page.orientation'] = __('education::education.validation.document_page');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $page = ['size' => $page['size'], 'orientation' => $page['orientation']];
        [$width, $height] = self::dimensions($page);

        $inputs = $this->inputs($inputs, $errors);
        $assets = $this->assetIds($company);
        $allowed = $this->placeholders($company, array_column($inputs, 'key'));

        $background = (array) ($layout['background'] ?? []);
        $clean = ['background' => ['color' => $this->color($background['color'] ?? null, 'layout.background.color', $errors, true), 'asset_id' => null]];
        if (! empty($background['asset_id'])) {
            if (! in_array($background['asset_id'], $assets, true)) {
                $errors['layout.background.asset_id'] = __('education::education.validation.reference');
            }
            $clean['background']['asset_id'] = (string) $background['asset_id'];
        }

        $elements = array_values((array) ($layout['elements'] ?? []));
        if (count($elements) > self::MAX_ELEMENTS) {
            $errors['layout.elements'] = __('education::education.validation.document_too_many', ['max' => self::MAX_ELEMENTS]);
            $elements = array_slice($elements, 0, self::MAX_ELEMENTS);
        }
        $ids = [];
        $clean['elements'] = [];
        foreach ($elements as $index => $element) {
            $at = "layout.elements.{$index}";
            $element = (array) $element;
            $type = $element['type'] ?? null;
            if (! in_array($type, self::ELEMENTS, true)) {
                $errors["{$at}.type"] = __('education::education.validation.document_element');

                continue;
            }
            $id = (string) ($element['id'] ?? '');
            if (preg_match('/^[a-z0-9_-]{1,20}$/', $id) !== 1 || isset($ids[$id])) {
                $errors["{$at}.id"] = __('education::education.validation.document_element');
            }
            $ids[$id] = true;

            $box = [];
            foreach (['x' => $width, 'y' => $height, 'w' => $width, 'h' => $height] as $key => $limit) {
                $value = filter_var($element[$key] ?? null, FILTER_VALIDATE_INT);
                if ($value === false || $value < 0 || $value > $limit) {
                    $errors["{$at}.{$key}"] = __('education::education.validation.document_outside');
                    $value = 0;
                }
                $box[$key] = $value;
            }
            if ($box['x'] + $box['w'] > $width || $box['y'] + $box['h'] > $height) {
                $errors["{$at}.w"] = __('education::education.validation.document_outside');
            }
            if (in_array($type, ['text', 'image', 'photo', 'qr', 'box'], true) && ($box['w'] < 10 || $box['h'] < 10)) {
                $errors["{$at}.w"] = __('education::education.validation.document_outside');
            }

            $item = ['id' => $id, 'type' => $type, ...$box];
            match ($type) {
                'text' => $item += $this->text($element, $at, $allowed, $errors),
                'image' => $item += $this->image($element, $at, $assets, $errors),
                'photo' => $item += ['fit' => $this->oneOf($element['fit'] ?? 'cover', ['contain', 'cover'], "{$at}.fit", $errors), 'radius' => $this->number($element['radius'] ?? 0, 0, 500, "{$at}.radius", $errors)],
                'qr' => $item += ['color' => $this->color($element['color'] ?? '#000000', "{$at}.color", $errors)],
                'line' => $item += ['color' => $this->color($element['color'] ?? '#000000', "{$at}.color", $errors), 'thickness' => $this->number($element['thickness'] ?? 3, 1, 50, "{$at}.thickness", $errors)],
                'box' => $item += [
                    'color' => $this->color($element['color'] ?? '#000000', "{$at}.color", $errors, true),
                    'thickness' => $this->number($element['thickness'] ?? 3, 0, 50, "{$at}.thickness", $errors),
                    'fill' => $this->color($element['fill'] ?? null, "{$at}.fill", $errors, true),
                    'radius' => $this->number($element['radius'] ?? 0, 0, 200, "{$at}.radius", $errors),
                ],
            };
            $clean['elements'][] = $item;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['page' => $page, 'layout' => $clean, 'inputs' => $inputs];
    }

    /**
     * The placeholders a design uses, by kind of value.
     *
     * @param  array<string, mixed>  $layout
     * @return list<string>
     */
    public static function used(array $layout): array
    {
        $found = [];
        foreach ((array) ($layout['elements'] ?? []) as $element) {
            if (($element['type'] ?? null) === 'text') {
                preg_match_all('/\{([a-z_]+(?:\.[a-z0-9_]+)?)\}/', (string) ($element['text'] ?? ''), $matches);
                array_push($found, ...$matches[1]);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Whether a design prints private details (date of birth, registration number, private own fields).
     *
     * @param  array<string, mixed>  $layout
     */
    public function isSensitive(Organization $company, array $layout): bool
    {
        $private = $this->fields->of($company, 'student')->where('is_sensitive', true)->map(fn (Field $field) => "field.{$field->key}")->all();

        return array_intersect(self::used($layout), [...self::SENSITIVE, ...$private]) !== [];
    }

    /**
     * Placeholders allowed here: the fixed ones, own student fields printed on documents, and the design's inputs.
     *
     * @param  list<string>  $inputKeys
     * @return list<string>
     */
    public function placeholders(Organization $company, array $inputKeys): array
    {
        $fields = $this->fields->of($company, 'student')->where('on_documents', true)->map(fn (Field $field) => "field.{$field->key}")->values()->all();

        return [...self::PLACEHOLDERS, ...$fields, ...array_map(fn (string $key) => "input.{$key}", $inputKeys)];
    }

    /** @return list<string> */
    private function assetIds(Organization $company): array
    {
        return app(Education::class)->query(DocumentAsset::class, $company)->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  list<string>  $allowed
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function text(array $element, string $at, array $allowed, array &$errors): array
    {
        $text = (string) ($element['text'] ?? '');
        if (mb_strlen($text) > 2000) {
            $errors["{$at}.text"] = __('validation.max.string', ['attribute' => 'text', 'max' => 2000]);
        }
        $unknown = array_diff(self::used(['elements' => [['type' => 'text', 'text' => $text]]]), $allowed);
        if ($unknown !== []) {
            $errors["{$at}.text"] = __('education::education.validation.document_placeholder', ['name' => '{'.reset($unknown).'}']);
        }

        return [
            'text' => $text,
            'size' => $this->number($element['size'] ?? 100, 40, 960, "{$at}.size", $errors),
            'weight' => $this->oneOf($element['weight'] ?? 'normal', ['normal', 'bold'], "{$at}.weight", $errors),
            'style' => $this->oneOf($element['style'] ?? 'normal', ['normal', 'italic'], "{$at}.style", $errors),
            'align' => $this->oneOf($element['align'] ?? 'start', ['start', 'center', 'end', 'justify'], "{$at}.align", $errors),
            'font' => $this->oneOf($element['font'] ?? 'sans', ['sans', 'serif'], "{$at}.font", $errors),
            'line_height' => $this->number($element['line_height'] ?? 130, 80, 300, "{$at}.line_height", $errors),
            'color' => $this->color($element['color'] ?? '#000000', "{$at}.color", $errors),
        ];
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  list<string>  $assets
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function image(array $element, string $at, array $assets, array &$errors): array
    {
        $asset = (string) ($element['asset_id'] ?? '');
        if (! in_array($asset, $assets, true)) {
            $errors["{$at}.asset_id"] = __('education::education.validation.reference');
        }

        return ['asset_id' => $asset, 'fit' => $this->oneOf($element['fit'] ?? 'contain', ['contain', 'cover'], "{$at}.fit", $errors)];
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @param  array<string, string>  $errors
     * @return list<array<string, mixed>>
     */
    private function inputs(array $inputs, array &$errors): array
    {
        if (count($inputs) > self::MAX_INPUTS) {
            $errors['inputs'] = __('education::education.validation.document_too_many', ['max' => self::MAX_INPUTS]);
        }
        $clean = [];
        $keys = [];
        foreach (array_slice(array_values($inputs), 0, self::MAX_INPUTS) as $index => $input) {
            $input = (array) $input;
            $key = (string) ($input['key'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_]{0,29}$/', $key) !== 1 || isset($keys[$key])) {
                $errors["inputs.{$index}.key"] = __('education::education.validation.document_input');
            }
            $keys[$key] = true;
            $label = array_filter(array_map(fn ($text) => is_string($text) ? mb_substr(trim($text), 0, 80) : '', (array) ($input['label'] ?? [])), fn ($text) => $text !== '');
            if (($label['en'] ?? '') === '') {
                $errors["inputs.{$index}.label.en"] = __('education::education.validation.document_input');
            }
            $clean[] = ['key' => $key, 'label' => $label, 'required' => (bool) ($input['required'] ?? false), 'multiline' => (bool) ($input['multiline'] ?? false)];
        }

        return $clean;
    }

    /** @param  array<string, string>  $errors */
    private function color(mixed $value, string $at, array &$errors, bool $nullable = false): ?string
    {
        if ($nullable && ($value === null || $value === '')) {
            return null;
        }
        if (! is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            $errors[$at] = __('education::education.validation.document_color');

            return '#000000';
        }

        return strtolower($value);
    }

    /** @param  array<string, string>  $errors */
    private function number(mixed $value, int $min, int $max, string $at, array &$errors): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            $errors[$at] = __('validation.between.numeric', ['attribute' => basename(str_replace('.', '/', $at)), 'min' => $min, 'max' => $max]);

            return $min;
        }

        return $value;
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, string>  $errors
     */
    private function oneOf(mixed $value, array $options, string $at, array &$errors): string
    {
        if (! in_array($value, $options, true)) {
            $errors[$at] = __('validation.in', ['attribute' => basename(str_replace('.', '/', $at))]);

            return $options[0];
        }

        return $value;
    }
}
