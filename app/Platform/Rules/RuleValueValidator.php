<?php

namespace App\Platform\Rules;

use App\Platform\Rules\Enums\RuleType;
use DateInterval;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates rule values and bounds, and compares values of a rule's type.
 * Decimals are compared as strings and money as integer minor units, so no
 * float ever touches a business number.
 */
class RuleValueValidator
{
    private Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator;
        $this->validator->setMaxErrors(1);
    }

    /**
     * @return string|null Why the value is invalid, or null when valid.
     */
    public function validate(RuleDefinition $rule, mixed $value): ?string
    {
        $schema = $rule->effectiveSchema();
        $result = $this->validator->validate(
            self::toJsonData($value),
            $schema === [] ? true : self::toJsonData($schema),
        );

        if ($result->isValid()) {
            return null;
        }

        $messages = (new ErrorFormatter)->format($result->error(), false);

        return (string) (array_values($messages)[0] ?? 'Invalid value.');
    }

    /**
     * Check a {"min", "max", "allowed"} bounds object for this rule.
     *
     * @return string|null Why the bounds are invalid, or null when valid.
     */
    public function validateBounds(RuleDefinition $rule, mixed $bounds): ?string
    {
        if (! is_array($bounds) || $bounds === [] || array_is_list($bounds)) {
            return 'Bounds must be an object with min, max and/or allowed.';
        }

        if (array_diff(array_keys($bounds), ['min', 'max', 'allowed']) !== []) {
            return 'Only min, max and allowed are supported.';
        }

        if ((isset($bounds['min']) || isset($bounds['max'])) && ! $rule->type->isOrdered()) {
            return "min/max are not supported for {$rule->type->value} rules.";
        }

        if (isset($bounds['allowed']) && ! $rule->type->supportsAllowedList()) {
            return "An allowed list is not supported for {$rule->type->value} rules.";
        }

        foreach (['min', 'max'] as $edge) {
            if (isset($bounds[$edge]) && ($error = $this->validate($rule, $bounds[$edge])) !== null) {
                return "{$edge}: {$error}";
            }
        }

        if (isset($bounds['min'], $bounds['max']) && $this->compare($rule, $bounds['min'], $bounds['max']) > 0) {
            return 'min must not be greater than max.';
        }

        if (isset($bounds['allowed'])) {
            if (! is_array($bounds['allowed']) || ! array_is_list($bounds['allowed']) || $bounds['allowed'] === []) {
                return 'allowed must be a non-empty list.';
            }

            foreach ($bounds['allowed'] as $item) {
                $candidate = $rule->type === RuleType::MultiEnum ? [$item] : $item;
                if (($error = $this->validate($rule, $candidate)) !== null) {
                    return "allowed: {$error}";
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $bounds
     */
    public function satisfies(RuleDefinition $rule, mixed $value, array $bounds): bool
    {
        if ($value === null) {
            return true;
        }

        if (isset($bounds['min']) && ! $this->comparable($rule, $value, $bounds['min'])) {
            return false;
        }

        if (isset($bounds['min']) && $this->compare($rule, $value, $bounds['min']) < 0) {
            return false;
        }

        if (isset($bounds['max']) && (! $this->comparable($rule, $value, $bounds['max']) || $this->compare($rule, $value, $bounds['max']) > 0)) {
            return false;
        }

        if (isset($bounds['allowed'])) {
            $values = $rule->type === RuleType::MultiEnum ? (array) $value : [$value];

            foreach ($values as $item) {
                if (! in_array($item, $bounds['allowed'], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Merge several bounds into the tightest combined bounds.
     *
     * @param  list<array<string, mixed>>  $boundsList
     * @return array<string, mixed>
     */
    public function combine(RuleDefinition $rule, array $boundsList): array
    {
        $combined = [];

        foreach ($boundsList as $bounds) {
            if (isset($bounds['min']) && (! isset($combined['min']) || $this->compare($rule, $bounds['min'], $combined['min']) > 0)) {
                $combined['min'] = $bounds['min'];
            }

            if (isset($bounds['max']) && (! isset($combined['max']) || $this->compare($rule, $bounds['max'], $combined['max']) < 0)) {
                $combined['max'] = $bounds['max'];
            }

            if (isset($bounds['allowed'])) {
                $combined['allowed'] = isset($combined['allowed'])
                    ? array_values(array_filter($combined['allowed'], fn ($item) => in_array($item, $bounds['allowed'], true)))
                    : $bounds['allowed'];
            }
        }

        return $combined;
    }

    /**
     * Whether $bounds only tighten $parent (children may narrow, never widen).
     *
     * @param  array<string, mixed>  $bounds
     * @param  array<string, mixed>  $parent
     */
    public function within(RuleDefinition $rule, array $bounds, array $parent): bool
    {
        // Every value the child bounds accept must also be accepted by the parent.
        $allowedFits = fn (array $edge) => isset($bounds['allowed'])
            && array_filter($bounds['allowed'], fn ($item) => ! $this->satisfies($rule, $item, $edge)) === [];

        if (isset($parent['min']) && ! (
            (isset($bounds['min']) && $this->comparable($rule, $bounds['min'], $parent['min']) && $this->compare($rule, $bounds['min'], $parent['min']) >= 0)
            || $allowedFits(['min' => $parent['min']])
        )) {
            return false;
        }

        if (isset($parent['max']) && ! (
            (isset($bounds['max']) && $this->comparable($rule, $bounds['max'], $parent['max']) && $this->compare($rule, $bounds['max'], $parent['max']) <= 0)
            || $allowedFits(['max' => $parent['max']])
        )) {
            return false;
        }

        if (isset($parent['allowed'])) {
            return isset($bounds['allowed'])
                && array_diff(array_map('json_encode', $bounds['allowed']), array_map('json_encode', $parent['allowed'])) === [];
        }

        return true;
    }

    /**
     * Pull a value back inside bounds (used only when stored data no longer
     * fits a parent's newer constraint).
     *
     * @param  array<string, mixed>  $bounds
     */
    public function clamp(RuleDefinition $rule, mixed $value, array $bounds): mixed
    {
        if (isset($bounds['min']) && ($value === null || ! $this->comparable($rule, $value, $bounds['min']) || $this->compare($rule, $value, $bounds['min']) < 0)) {
            $value = $bounds['min'];
        }

        if (isset($bounds['max']) && ($value === null || ! $this->comparable($rule, $value, $bounds['max']) || $this->compare($rule, $value, $bounds['max']) > 0)) {
            $value = $bounds['max'];
        }

        if (isset($bounds['allowed']) && ! $this->satisfies($rule, $value, ['allowed' => $bounds['allowed']])) {
            $value = $rule->type === RuleType::MultiEnum
                ? array_values(array_filter((array) $value, fn ($item) => in_array($item, $bounds['allowed'], true)))
                : $bounds['allowed'][0];
        }

        return $value;
    }

    /**
     * @return int -1, 0 or 1
     */
    public function compare(RuleDefinition $rule, mixed $a, mixed $b): int
    {
        return match ($rule->type) {
            RuleType::Integer => $a <=> $b,
            RuleType::Decimal => self::compareDecimal((string) $a, (string) $b),
            RuleType::Money => $a['amount'] <=> $b['amount'],
            RuleType::Duration => self::seconds($a) <=> self::seconds($b),
            RuleType::Time, RuleType::Date => strcmp((string) $a, (string) $b) <=> 0,
            default => 0,
        };
    }

    private function comparable(RuleDefinition $rule, mixed $a, mixed $b): bool
    {
        return $rule->type !== RuleType::Money || ($a['currency'] ?? null) === ($b['currency'] ?? null);
    }

    /**
     * Compare two decimal strings exactly.
     */
    public static function compareDecimal(string $a, string $b): int
    {
        [$signA, $intA, $fracA] = self::splitDecimal($a);
        [$signB, $intB, $fracB] = self::splitDecimal($b);

        if ($intA === '0' && $fracA === '') {
            $signA = 1;
        }
        if ($intB === '0' && $fracB === '') {
            $signB = 1;
        }

        if ($signA !== $signB) {
            return $signA <=> $signB;
        }

        $length = max(strlen($fracA), strlen($fracB));
        $fracA = str_pad($fracA, $length, '0');
        $fracB = str_pad($fracB, $length, '0');

        $magnitude = strlen($intA) <=> strlen($intB) ?: strcmp($intA, $intB) <=> 0 ?: strcmp($fracA, $fracB) <=> 0;

        return $signA * $magnitude;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private static function splitDecimal(string $value): array
    {
        $sign = str_starts_with($value, '-') ? -1 : 1;
        [$int, $frac] = array_pad(explode('.', ltrim($value, '-+')), 2, '');

        return [$sign, ltrim($int, '0') ?: '0', rtrim($frac, '0')];
    }

    private static function seconds(string $duration): int
    {
        $interval = new DateInterval($duration);

        return $interval->d * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
    }

    /**
     * PHP arrays to JSON data (assoc arrays become objects).
     */
    private static function toJsonData(mixed $value): mixed
    {
        return json_decode((string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION));
    }
}
