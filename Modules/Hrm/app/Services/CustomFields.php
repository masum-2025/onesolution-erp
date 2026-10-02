<?php

namespace Modules\Hrm\Services;

use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Hrm\Enums\CustomFieldType;
use Modules\Hrm\Models\CustomField;
use Throwable;

/**
 * The extra fields that apply to a unit (its own and those of the units above
 * it inside the company) and the checks on their values. Used by hiring,
 * editing and imports alike, so a value is accepted the same way everywhere.
 */
class CustomFields
{
    public const TEXT_MAX = 255;

    public function __construct(private Units $units) {}

    /**
     * Fields of the unit and the units above it in its company, in display order.
     *
     * @return Collection<int, CustomField>
     */
    public function applicableTo(Organization $unit, bool $activeOnly = true): Collection
    {
        $company = $this->units->companyOf($unit);

        // The ids are the unit's own chain (already authorized), so the scope is not needed.
        return CustomField::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('organization_id', [...$unit->ancestorIds(), $unit->getKey()])
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')->orderBy('key')
            ->get();
    }

    /**
     * Checks $input (key => raw value) against the unit's fields and merges it
     * into $existing. A null or empty value clears a field. When $requireAll,
     * every required field must end up with a value (hiring, importing).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function check(Organization $unit, array $input, array $existing, bool $requireAll): array
    {
        $fields = $this->applicableTo($unit)->keyBy('key');
        $values = $existing;
        $errors = [];

        foreach ($input as $key => $raw) {
            $key = (string) $key;
            $field = $fields->get($key);
            if ($field === null) {
                $errors["custom.{$key}"] = __('hrm::hrm.validation.custom_unknown');

                continue;
            }

            if ($raw === null || $raw === '' || (is_string($raw) && trim($raw) === '')) {
                unset($values[$key]);

                continue;
            }

            [$value, $error] = $this->normalize($field, $raw);
            if ($error !== null) {
                $errors["custom.{$key}"] = $error;
            } else {
                $values[$key] = $value;
            }
        }

        foreach ($fields as $key => $field) {
            $touched = array_key_exists($key, $input);
            if ($field->is_required && ($requireAll || $touched) && ! array_key_exists($key, $values) && ! isset($errors["custom.{$key}"])) {
                $errors["custom.{$key}"] = __('hrm::hrm.validation.required_by_rule');
            }
        }

        ksort($values);

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @return array{0: mixed, 1: string|null} The stored value, or an error.
     */
    public function normalize(CustomField $field, mixed $raw): array
    {
        $text = is_scalar($raw) ? trim((string) $raw) : null;

        return match ($field->type) {
            CustomFieldType::Text => $text !== null && mb_strlen($text) <= self::TEXT_MAX
                ? [$text, null]
                : [null, __('hrm::hrm.validation.custom_text', ['max' => self::TEXT_MAX])],
            CustomFieldType::Number => $text !== null && ! is_bool($raw) && preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $text) === 1
                ? [$this->canonicalNumber($text), null]
                : [null, __('hrm::hrm.validation.custom_number')],
            CustomFieldType::Date => ($date = $this->date($text)) !== null
                ? [$date, null]
                : [null, __('hrm::hrm.validation.custom_date')],
            CustomFieldType::Choice => $text !== null && in_array($text, $field->optionValues(), true)
                ? [$text, null]
                : [null, __('hrm::hrm.validation.custom_choice')],
            CustomFieldType::YesNo => ($bool = $this->yesNo($raw)) !== null
                ? [$bool, null]
                : [null, __('hrm::hrm.validation.custom_yes_no')],
        };
    }

    /** "007.50" -> "7.5", "-0" -> "0": one spelling per number. */
    private function canonicalNumber(string $text): string
    {
        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '-'), 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        $number = $fraction === '' ? $whole : "{$whole}.{$fraction}";

        return $negative && $number !== '0' ? "-{$number}" : $number;
    }

    /** YYYY-MM-DD, or DD/MM/YYYY as spreadsheets in Bangladesh write it. */
    private function date(?string $text): ?string
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

    private function yesNo(mixed $raw): ?bool
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
