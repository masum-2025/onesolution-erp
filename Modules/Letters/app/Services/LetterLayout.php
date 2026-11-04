<?php

namespace Modules\Letters\Services;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Models\LetterAsset;

/**
 * What a letter design may hold, checked the same way for every company.
 *
 * - The page: A4 or US Letter, margins 10-40 mm.
 * - The letterhead: the company's name (and logo), an image of its own
 *   letterhead, or nothing (printed on paper that has one); its address
 *   per language; aligned to the start, centre or end.
 * - What is asked when issuing: up to 10 inputs ({input.key}): a line, a
 *   longer text or a date, required or not, labelled per language.
 * - The body per language as blocks (never HTML): heading, paragraph (with
 *   **bold**, *italic* and __underline__), list, table (rows written here,
 *   or a table field such as the pay breakdown), space, signature (name,
 *   title, signature and stamp images), page break.
 * - Every {placeholder} is a known field; an unknown one is refused with
 *   where it is, so a typo never prints as "{employee.nmae}".
 */
class LetterLayout
{
    public const KINDS = ['appointment', 'joining', 'confirmation', 'increment', 'promotion', 'transfer', 'warning', 'experience', 'relieving', 'salary_certificate', 'noc', 'other'];

    public const BLOCKS = ['heading', 'paragraph', 'list', 'table', 'spacer', 'signature', 'page_break'];

    private const MAX_BLOCKS = 80;

    private const ALIGN = ['start', 'center', 'end'];

    public function __construct(private Place $place, private LetterFields $fields) {}

    /**
     * The design, checked and in its stored shape; uses_salary when a salary field is used.
     *
     * @param  array<string, mixed>  $data  kind, code, page, letterhead, inputs, bodies
     * @return array{kind: string, code: string, page: array<string, mixed>, letterhead: array<string, mixed>, inputs: list<array<string, mixed>>, bodies: array<string, list<array<string, mixed>>>, uses_salary: bool}
     */
    public function check(Organization $company, array $data): array
    {
        $errors = [];
        $locales = LanguageRegistry::codes();
        $text = fn (mixed $value, int $max) => is_string($value) && mb_strlen($value) <= $max;
        $texts = fn (mixed $value, int $max) => is_array($value) && array_diff(array_keys($value), $locales) === []
            && collect($value)->every(fn ($item) => $item === null || $text($item, $max));

        $kind = (string) ($data['kind'] ?? '');
        if (! in_array($kind, self::KINDS, true)) {
            $errors['kind'] = __('letters::letters.validation.kind');
        }
        $code = strtoupper((string) ($data['code'] ?? ''));
        if (preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $code) !== 1) {
            $errors['code'] = __('letters::letters.validation.code');
        }

        $page = (array) ($data['page'] ?? []);
        $page = ['size' => $page['size'] ?? 'A4', 'margin_mm' => (int) ($page['margin_mm'] ?? 20)];
        if (! in_array($page['size'], ['A4', 'Letter'], true) || $page['margin_mm'] < 10 || $page['margin_mm'] > 40) {
            $errors['page'] = __('letters::letters.validation.page');
        }

        $head = (array) ($data['letterhead'] ?? []);
        $letterhead = [
            'style' => $head['style'] ?? 'name',
            'logo' => (bool) ($head['logo'] ?? true),
            'image_id' => $head['image_id'] ?? null,
            'address' => array_filter((array) ($head['address'] ?? []), fn ($item) => is_string($item) && trim($item) !== ''),
            'align' => $head['align'] ?? 'center',
        ];
        if (! in_array($letterhead['style'], ['name', 'image', 'none'], true) || ! in_array($letterhead['align'], self::ALIGN, true)
            || ! $texts($letterhead['address'], 300)) {
            $errors['letterhead'] = __('letters::letters.validation.letterhead');
        }
        if ($letterhead['style'] === 'image' && ! $this->assetOk($company, $letterhead['image_id'], 'letterhead')) {
            $errors['letterhead.image_id'] = __('letters::letters.validation.image');
        }
        if ($letterhead['style'] !== 'image') {
            $letterhead['image_id'] = null;
        }

        $inputs = [];
        $inputKeys = [];
        foreach (array_values((array) ($data['inputs'] ?? [])) as $index => $input) {
            $key = (string) ($input['key'] ?? '');
            $type = $input['type'] ?? 'text';
            if ($index >= 10 || preg_match('/^[a-z][a-z0-9_]{1,29}$/', $key) !== 1 || in_array($key, $inputKeys, true)
                || ! in_array($type, ['text', 'long_text', 'date'], true) || ! $texts($input['label'] ?? null, 80) || blank($input['label']['en'] ?? null)) {
                $errors["inputs.{$index}"] = __('letters::letters.validation.input');

                continue;
            }
            $inputKeys[] = $key;
            $inputs[] = ['key' => $key, 'type' => $type, 'required' => (bool) ($input['required'] ?? false), 'label' => array_filter($input['label'])];
        }

        $known = $this->fields->known($company, app()->getLocale());
        $usesSalary = false;
        $bodies = [];
        $given = (array) ($data['bodies'] ?? []);
        if (array_diff(array_keys($given), $locales) !== [] || ! isset($given['en'])) {
            $errors['bodies'] = __('letters::letters.validation.bodies');
            $given = array_intersect_key($given, array_flip($locales));
        }
        foreach ($given as $locale => $blocks) {
            $blocks = array_values((array) $blocks);
            if ($blocks === [] || count($blocks) > self::MAX_BLOCKS) {
                $errors["bodies.{$locale}"] = __('letters::letters.validation.blocks', ['max' => self::MAX_BLOCKS]);

                continue;
            }
            $clean = [];
            foreach ($blocks as $index => $block) {
                $path = "bodies.{$locale}.{$index}";
                $checked = $this->block((array) $block, $text, $company);
                if ($checked === null) {
                    $errors[$path] = __('letters::letters.validation.block');

                    continue;
                }
                foreach ($this->placeholdersOf($checked) as $placeholder) {
                    $isInput = str_starts_with($placeholder, 'input.') && in_array(substr($placeholder, 6), $inputKeys, true);
                    if (! $isInput && (! isset($known[$placeholder]) || $known[$placeholder]['table'])) {
                        $errors[$path] = __('letters::letters.validation.placeholder', ['name' => '{'.$placeholder.'}']);
                    } elseif (! $isInput && $known[$placeholder]['salary']) {
                        $usesSalary = true;
                    }
                }
                if ($checked['type'] === 'table' && isset($checked['field'])) {
                    if (! isset($known[$checked['field']]) || ! $known[$checked['field']]['table']) {
                        $errors[$path] = __('letters::letters.validation.placeholder', ['name' => $checked['field']]);
                    } elseif ($known[$checked['field']]['salary']) {
                        $usesSalary = true;
                    }
                }
                $clean[] = $checked;
            }
            $bodies[$locale] = $clean;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['kind' => $kind, 'code' => $code, 'page' => $page, 'letterhead' => $letterhead, 'inputs' => $inputs, 'bodies' => $bodies, 'uses_salary' => $usesSalary];
    }

    /**
     * Every {placeholder} in a block's texts.
     *
     * @param  array<string, mixed>  $block
     * @return list<string>
     */
    public function placeholdersOf(array $block): array
    {
        $texts = [];
        array_walk_recursive($block, function ($value, $key) use (&$texts) {
            if (is_string($value) && ! in_array($key, ['type', 'align', 'size', 'field', 'image_id', 'stamp_id'], true)) {
                $texts[] = $value;
            }
        });
        preg_match_all('/\{([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)\}/', implode("\n", $texts), $found);

        return array_values(array_unique($found[1]));
    }

    /**
     * One block in its stored shape, or null when it is not a block.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function block(array $block, callable $text, Organization $company): ?array
    {
        $align = in_array($block['align'] ?? 'start', [...self::ALIGN, 'justify'], true) ? ($block['align'] ?? 'start') : null;

        return match ($block['type'] ?? null) {
            'heading' => $text($block['text'] ?? null, 200) && $align !== null
                ? ['type' => 'heading', 'text' => $block['text'], 'level' => in_array($block['level'] ?? 2, [1, 2], true) ? ($block['level'] ?? 2) : 2, 'align' => $align] : null,
            'paragraph' => $text($block['text'] ?? null, 3000) && $align !== null ? ['type' => 'paragraph', 'text' => $block['text'], 'align' => $align] : null,
            'list' => is_array($block['items'] ?? null) && count($block['items']) >= 1 && count($block['items']) <= 30 && collect($block['items'])->every(fn ($item) => $text($item, 500))
                ? ['type' => 'list', 'ordered' => (bool) ($block['ordered'] ?? false), 'items' => array_values($block['items'])] : null,
            'table' => $this->table($block, $text),
            'spacer' => in_array($block['size'] ?? 'md', ['sm', 'md', 'lg'], true) ? ['type' => 'spacer', 'size' => $block['size'] ?? 'md'] : null,
            'signature' => $text($block['name'] ?? '', 100) && $text($block['title'] ?? '', 150) && $align !== null
                && (($block['image_id'] ?? null) === null || $this->assetOk($company, $block['image_id'], 'signature'))
                && (($block['stamp_id'] ?? null) === null || $this->assetOk($company, $block['stamp_id'], 'stamp'))
                ? ['type' => 'signature', 'name' => $block['name'] ?? '', 'title' => $block['title'] ?? '', 'image_id' => $block['image_id'] ?? null, 'stamp_id' => $block['stamp_id'] ?? null, 'align' => $align] : null,
            'page_break' => ['type' => 'page_break'],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function table(array $block, callable $text): ?array
    {
        if (isset($block['field'])) {
            return is_string($block['field']) && preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $block['field']) === 1 ? ['type' => 'table', 'field' => $block['field']] : null;
        }
        $rows = $block['rows'] ?? null;
        if (! is_array($rows) || count($rows) < 1 || count($rows) > 30) {
            return null;
        }
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) !== 2 || ! $text($row[0] ?? null, 200) || ! $text($row[1] ?? null, 500)) {
                return null;
            }
        }

        return ['type' => 'table', 'rows' => array_map(fn (array $row) => [$row[0], $row[1]], array_values($rows))];
    }

    private function assetOk(Organization $company, mixed $id, string $kind): bool
    {
        return is_string($id) && $this->place->query(LetterAsset::class, $company)->whereKey($id)->where('kind', $kind)->where('is_active', true)->exists();
    }
}
