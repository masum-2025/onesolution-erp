<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Models\Field;
use Throwable;

/**
 * The institution's own fields on students, guardians and admissions
 * (religion, blood group, previous school…: what a country or an
 * institution needs is data, never code). A field's key and type never
 * change once made; it is switched off, never removed. A field may show in
 * the portal, be printed on certificates and ID cards, or be sensitive
 * (shown only with education.view_sensitive).
 */
class Fields
{
    public const TEXT_MAX = 255;

    public const LONG_TEXT_MAX = 2000;

    public const MAX_PER_ENTITY = 40;

    public function __construct(private Education $education, private AuditLogger $audit) {}

    /**
     * @return Collection<int, Field>
     */
    public function of(Organization $company, string $entity, bool $activeOnly = true): Collection
    {
        return $this->education->query(Field::class, $company)->where('entity', $entity)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')->orderBy('key')->get();
    }

    /**
     * @param  array<string, mixed>  $data  Validated by FieldRequest.
     */
    public function save(Organization $company, ?Field $field, ?int $baseVersion, array $data, User $actor): Field
    {
        return $this->education->transaction($company, function () use ($company, $field, $baseVersion, $data, $actor) {
            if ($field === null) {
                if ($this->education->query(Field::class, $company)->where('entity', $data['entity'])->where('key', $data['key'])->exists()) {
                    throw ValidationException::withMessages(['key' => __('education::education.validation.taken')]);
                }
                if ($this->education->query(Field::class, $company)->where('entity', $data['entity'])->count() >= self::MAX_PER_ENTITY) {
                    throw ValidationException::withMessages(['key' => __('education::education.validation.field_too_many', ['max' => self::MAX_PER_ENTITY])]);
                }
                $field = new Field;
                $field->fill(['organization_id' => $company->getKey(), 'entity' => $data['entity'], 'key' => $data['key'], 'type' => $data['type'], 'version' => 1]);
                $old = null;
            } else {
                /** @var Field $field */
                $field = $this->education->query(Field::class, $company)->whereKey($field->getKey())->lockForUpdate()->firstOrFail();
                if ($field->version !== $baseVersion) {
                    throw EducationException::versionConflict(['version' => $field->version]);
                }
                $old = $this->values($field);
                $field->version++;
            }
            $choices = in_array($field->type, ['choice', 'multi_choice'], true);
            if ($choices && array_key_exists('options', $data) && count($data['options'] ?? []) < 1) {
                throw ValidationException::withMessages(['options' => __('education::education.validation.field_options')]);
            }
            $field->fill(array_intersect_key($data, array_flip(['options', 'is_required', 'portal_visible', 'on_documents', 'is_sensitive', 'sort_order', 'is_active'])));
            if (! $choices) {
                $field->options = null;
            }
            // A sensitive value never shows in the portal.
            if ($field->is_sensitive) {
                $field->portal_visible = false;
            }
            if (isset($data['label'])) {
                $field->putTexts('label', $data['label']);
            }
            $field->save();
            $this->audit->record($old === null ? 'education.field_created' : 'education.field_updated', $field, old: $old ?? [], new: $this->values($field), actor: $actor, organizationId: $company->getKey());

            return $field;
        });
    }

    /**
     * Checks $input (key => raw value) against the active fields of an
     * entity and merges it into $existing. Empty clears a value. With
     * $requireAll (creating), every required field must end with one.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function check(Organization $company, string $entity, array $input, array $existing, bool $requireAll, string $prefix = 'extra'): array
    {
        $fields = $this->of($company, $entity)->keyBy('key');
        $values = $existing;
        $errors = [];
        foreach ($input as $key => $raw) {
            $key = (string) $key;
            $field = $fields->get($key);
            if ($field === null) {
                $errors["{$prefix}.{$key}"] = __('education::education.validation.field_unknown');

                continue;
            }
            if ($raw === null || $raw === '' || $raw === [] || (is_string($raw) && trim($raw) === '')) {
                unset($values[$key]);

                continue;
            }
            [$value, $error] = $this->normalize($field, $raw);
            if ($error !== null) {
                $errors["{$prefix}.{$key}"] = $error;
            } else {
                $values[$key] = $value;
            }
        }
        foreach ($fields as $key => $field) {
            if ($field->is_required && ($requireAll || array_key_exists($key, $input)) && ! array_key_exists($key, $values) && ! isset($errors["{$prefix}.{$key}"])) {
                $errors["{$prefix}.{$key}"] = __('education::education.validation.field_required', ['field' => $field->textIn('label')]);
            }
        }
        ksort($values);

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Like check(), but throws the errors as a validation failure.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public function apply(Organization $company, string $entity, array $input, array $existing, bool $requireAll, string $prefix = 'extra'): array
    {
        $result = $this->check($company, $entity, $input, $existing, $requireAll, $prefix);
        if ($result['errors'] !== []) {
            throw ValidationException::withMessages($result['errors']);
        }

        return $result['values'];
    }

    /**
     * Values a reader may see: without sensitive fields unless allowed, and
     * only portal fields in the portal.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>
     */
    public function visible(Organization $company, string $entity, ?array $values, bool $sensitive, bool $portal = false): array
    {
        if (! $values) {
            return [];
        }
        $fields = $this->of($company, $entity, false)->keyBy('key');

        return array_filter($values, function ($value, $key) use ($fields, $sensitive, $portal) {
            $field = $fields->get($key);

            return $field !== null && ($sensitive || ! $field->is_sensitive) && (! $portal || $field->portal_visible);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array{0: mixed, 1: string|null} The stored value, or an error.
     */
    public function normalize(Field $field, mixed $raw): array
    {
        $text = is_scalar($raw) && ! is_bool($raw) ? trim((string) $raw) : null;
        $allowed = array_column($field->options ?? [], 'value');

        return match ($field->type) {
            'text' => $text !== null && mb_strlen($text) <= self::TEXT_MAX ? [$text, null] : [null, __('education::education.validation.field_text', ['max' => self::TEXT_MAX])],
            'long_text' => $text !== null && mb_strlen($text) <= self::LONG_TEXT_MAX ? [$text, null] : [null, __('education::education.validation.field_text', ['max' => self::LONG_TEXT_MAX])],
            'number' => $text !== null && preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $text) === 1 ? [$text, null] : [null, __('education::education.validation.field_number')],
            'date' => ($date = self::date($text)) !== null ? [$date, null] : [null, __('education::education.validation.field_date')],
            'choice' => $text !== null && in_array($text, $allowed, true) ? [$text, null] : [null, __('education::education.validation.field_choice')],
            'multi_choice' => is_array($raw) && array_is_list($raw) && $raw !== [] && array_diff($raw, $allowed) === []
                ? [array_values(array_unique(array_map('strval', $raw))), null]
                : [null, __('education::education.validation.field_choice')],
            'yes_no' => ($bool = self::yesNo($raw)) !== null ? [$bool, null] : [null, __('education::education.validation.field_yes_no')],
            default => [null, __('education::education.validation.field_unknown')],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function values(Field $field): array
    {
        return [...$field->only(['entity', 'key', 'type', 'options', 'is_required', 'portal_visible', 'on_documents', 'is_sensitive', 'sort_order', 'is_active']), 'label' => $field->texts('label')];
    }

    /** YYYY-MM-DD, or DD/MM/YYYY as spreadsheets in Bangladesh write it. */
    private static function date(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $text);
            } catch (Throwable) {
                continue;
            }
            if ($date !== null && $date->format(ltrim($format, '!')) === $text) {
                return $date->toDateString();
            }
        }

        return null;
    }

    private static function yesNo(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        return match (mb_strtolower(trim((string) $raw))) {
            '1', 'true', 'yes', 'y', 'হ্যাঁ' => true,
            '0', 'false', 'no', 'n', 'না' => false,
            default => null,
        };
    }
}
