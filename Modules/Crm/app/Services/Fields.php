<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Models\Field;
use Throwable;

/**
 * The extra fields a company adds to its contacts, deals, estimates and
 * quotations and their lines, and the checks on their values: used by
 * every form, import and offline sync alike. A field's key and type never
 * change once made (stored values depend on them); label, options, order,
 * required and printed can change; a field is switched off, never removed,
 * so old records keep their values.
 */
class Fields
{
    public const TEXT_MAX = 255;

    public const LONG_TEXT_MAX = 2000;

    /** Most fields one company keeps per kind of record. */
    public const MAX_PER_ENTITY = 40;

    public function __construct(private Crm $crm, private AuditLogger $audit) {}

    /**
     * @return Collection<int, Field>
     */
    public function of(Organization $company, string $entity, bool $activeOnly = true): Collection
    {
        return $this->crm->query(Field::class, $company)->where('entity', $entity)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')->orderBy('key')->get();
    }

    /**
     * @param  array<string, mixed>  $data  Validated by FieldRequest.
     */
    public function save(Organization $company, ?Field $field, ?int $baseVersion, array $data, User $actor): Field
    {
        return $this->crm->transaction($company, function () use ($company, $field, $baseVersion, $data, $actor) {
            if ($field === null) {
                if ($this->crm->query(Field::class, $company)->where('entity', $data['entity'])->where('key', $data['key'])->exists()) {
                    throw ValidationException::withMessages(['key' => __('crm::crm.validation.field_key_taken')]);
                }
                if ($this->crm->query(Field::class, $company)->where('entity', $data['entity'])->count() >= self::MAX_PER_ENTITY) {
                    throw ValidationException::withMessages(['key' => __('crm::crm.validation.field_too_many', ['max' => self::MAX_PER_ENTITY])]);
                }
                $field = new Field;
                $field->fill(['organization_id' => $company->getKey(), 'entity' => $data['entity'], 'key' => $data['key'], 'type' => $data['type'], 'version' => 1]);
                $old = null;
            } else {
                /** @var Field $field */
                $field = $this->crm->query(Field::class, $company)->whereKey($field->getKey())->lockForUpdate()->firstOrFail();
                if ($field->version !== $baseVersion) {
                    throw CrmException::versionConflict(['version' => $field->version]);
                }
                $old = $this->values($field);
                $field->version++;
            }
            $type = $field->type;
            if ($type === 'choice' && array_key_exists('options', $data) && count($data['options'] ?? []) < 1) {
                throw ValidationException::withMessages(['options' => __('crm::crm.validation.field_options')]);
            }
            $field->fill(array_intersect_key($data, array_flip(['options', 'is_required', 'on_print', 'sort_order', 'is_active'])));
            if ($type !== 'choice') {
                $field->options = null;
            }
            if (isset($data['label'])) {
                $field->putTexts('label', $data['label']);
            }
            $field->save();
            $this->audit->record($old === null ? 'crm.field_created' : 'crm.field_updated', $field, old: $old ?? [], new: $this->values($field), actor: $actor, organizationId: $company->getKey());

            return $field;
        });
    }

    /**
     * Checks $input (key => raw value) against the company's active fields
     * of a kind of record and merges it into $existing. Empty clears a value.
     * With $requireAll (creating), every required field must end with one.
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
                $errors["{$prefix}.{$key}"] = __('crm::crm.validation.field_unknown');

                continue;
            }
            if ($raw === null || $raw === '' || (is_string($raw) && trim($raw) === '')) {
                unset($values[$key]);

                continue;
            }
            [$value, $error] = $this->normalize($field, $raw, $this->crm->currency($company));
            if ($error !== null) {
                $errors["{$prefix}.{$key}"] = $error;
            } else {
                $values[$key] = $value;
            }
        }
        foreach ($fields as $key => $field) {
            if ($field->is_required && ($requireAll || array_key_exists($key, $input)) && ! array_key_exists($key, $values) && ! isset($errors["{$prefix}.{$key}"])) {
                $errors["{$prefix}.{$key}"] = __('crm::crm.validation.field_required', ['field' => $field->textIn('label')]);
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
     * @return array{0: mixed, 1: string|null} The stored value, or an error.
     */
    public function normalize(Field $field, mixed $raw, string $currency): array
    {
        $text = is_scalar($raw) && ! is_bool($raw) ? trim((string) $raw) : null;

        return match ($field->type) {
            'text' => $text !== null && mb_strlen($text) <= self::TEXT_MAX ? [$text, null] : [null, __('crm::crm.validation.field_text', ['max' => self::TEXT_MAX])],
            'long_text' => $text !== null && mb_strlen($text) <= self::LONG_TEXT_MAX ? [$text, null] : [null, __('crm::crm.validation.field_text', ['max' => self::LONG_TEXT_MAX])],
            'number' => $text !== null && preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $text) === 1 ? [self::canonicalNumber($text), null] : [null, __('crm::crm.validation.field_number')],
            // Money is kept as integer minor units of the company's currency.
            'money' => is_int($raw) && $raw >= 0 && $raw <= 999999999999999 ? [$raw, null] : [null, __('crm::crm.validation.field_money', ['currency' => $currency])],
            'date' => ($date = self::date($text)) !== null ? [$date, null] : [null, __('crm::crm.validation.field_date')],
            'choice' => $text !== null && in_array($text, array_column($field->options ?? [], 'value'), true) ? [$text, null] : [null, __('crm::crm.validation.field_choice')],
            'yes_no' => ($bool = self::yesNo($raw)) !== null ? [$bool, null] : [null, __('crm::crm.validation.field_yes_no')],
            default => [null, __('crm::crm.validation.field_unknown')],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Field $field): array
    {
        return [...$field->only(['entity', 'key', 'type', 'options', 'is_required', 'on_print', 'sort_order', 'is_active']), 'label' => $field->texts('label')];
    }

    /** "007.50" -> "7.5": one spelling per number, never a float. */
    private static function canonicalNumber(string $text): string
    {
        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '-'), 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        $number = $fraction === '' ? $whole : "{$whole}.{$fraction}";

        return $negative && $number !== '0' ? "-{$number}" : $number;
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

        return match (is_scalar($raw) ? mb_strtolower(trim((string) $raw)) : null) {
            'yes', 'true', '1', 'হ্যাঁ' => true,
            'no', 'false', '0', 'না' => false,
            default => null,
        };
    }
}
