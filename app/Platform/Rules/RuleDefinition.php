<?php

namespace App\Platform\Rules;

use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleType;

/**
 * A rule as declared by a module manifest (or the platform core rules file).
 */
final readonly class RuleDefinition
{
    /**
     * @param  array<string, mixed>  $schema  Extra JSON Schema on top of the type's base schema.
     * @param  list<RuleScope>  $overridableLevels
     */
    public function __construct(
        public string $key,
        public string $moduleKey,
        public RuleType $type,
        public array $schema,
        public mixed $default,
        public bool $nullable,
        public string $label,
        public string $description,
        public array $overridableLevels,
        public string $editPermission,
        public bool $requiresApproval,
        public bool $sensitive,
        public bool $countrySpecific,
        public string $category,
        public int $sortOrder,
        // Who may write values: a partner in its console (false = platform decides
        // the partner's value, e.g. governance), organizations in the rule editor
        // (false = written for them by their partner, e.g. per-client limits).
        public bool $partnerEditable = true,
        public bool $organizationEditable = true,
    ) {}

    /**
     * Full JSON Schema a stored value must match.
     *
     * @return array<string, mixed>
     */
    public function effectiveSchema(): array
    {
        $parts = array_values(array_filter([$this->type->baseSchema(), $this->schema]));
        $schema = match (count($parts)) {
            0 => new \stdClass,
            1 => $parts[0],
            default => ['allOf' => $parts],
        };

        return $this->nullable ? ['anyOf' => [['type' => 'null'], $schema]] : (array) $schema;
    }

    public function allowsLevel(RuleScope $scope): bool
    {
        return in_array($scope, $this->overridableLevels, true);
    }

    /** Changes need a second person's approval (maker-checker). */
    public function needsApproval(): bool
    {
        return $this->requiresApproval || $this->sensitive;
    }

    public function label(?string $locale = null): string
    {
        return __($this->label, [], $locale);
    }
}
