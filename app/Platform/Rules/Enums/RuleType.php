<?php

namespace App\Platform\Rules\Enums;

enum RuleType: string
{
    case Boolean = 'boolean';
    case Integer = 'integer';
    // Stored as a numeric string ("1.5"), never a float.
    case Decimal = 'decimal';
    case String = 'string';
    case Enum = 'enum';
    case MultiEnum = 'multi_enum';
    // ISO 8601 duration without years/months, e.g. "PT15M", "P1DT2H".
    case Duration = 'duration';
    // {"amount": <integer minor units>, "currency": "BDT"}
    case Money = 'money';
    // "HH:MM"
    case Time = 'time';
    // Format set by the rule's schema ("YYYY-MM-DD" or "MM-DD").
    case Date = 'date';
    case Json = 'json';
    // A list of rows, e.g. tax slabs.
    case Table = 'table';

    /**
     * JSON Schema every value of this type must match, before the rule's own schema.
     *
     * @return array<string, mixed>
     */
    public function baseSchema(): array
    {
        return match ($this) {
            self::Boolean => ['type' => 'boolean'],
            self::Integer => ['type' => 'integer'],
            self::Decimal => ['type' => 'string', 'pattern' => '^-?\d{1,18}(\.\d{1,8})?$'],
            self::String, self::Enum, self::Date => ['type' => 'string'],
            self::MultiEnum => ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true],
            self::Duration => ['type' => 'string', 'pattern' => '^P(?!$)(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$'],
            self::Money => [
                'type' => 'object',
                'required' => ['amount', 'currency'],
                'properties' => [
                    'amount' => ['type' => 'integer'],
                    'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                ],
                'additionalProperties' => false,
            ],
            self::Time => ['type' => 'string', 'pattern' => '^([01]\d|2[0-3]):[0-5]\d$'],
            self::Json => [],
            self::Table => ['type' => 'array', 'items' => ['type' => 'object']],
        };
    }

    /** Types whose values have an order, so min/max bounds make sense. */
    public function isOrdered(): bool
    {
        return in_array($this, [self::Integer, self::Decimal, self::Duration, self::Money, self::Time, self::Date], true);
    }

    /** Types that can be limited to a list of allowed values. */
    public function supportsAllowedList(): bool
    {
        return in_array($this, [self::Integer, self::Decimal, self::String, self::Enum, self::MultiEnum], true);
    }
}
