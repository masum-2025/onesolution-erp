<?php

namespace App\Platform\Rules;

use App\Platform\Modules\ModuleRegistry;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Enums\RuleType;
use App\Platform\Rules\Exceptions\RuleException;
use InvalidArgumentException;
use LogicException;

/**
 * Every rule definition: platform core rules plus each module manifest's
 * `rules`. Validated once when loaded; a broken definition fails fast.
 */
final class RuleCatalog
{
    public const CORE_MODULE = 'core';

    /** @var array<string, RuleDefinition> */
    private array $rules = [];

    /**
     * @param  array<string, list<array<string, mixed>>>  $rulesByModule
     */
    public function __construct(array $rulesByModule, RuleValueValidator $validator)
    {
        foreach ($rulesByModule as $moduleKey => $definitions) {
            foreach ($definitions as $definition) {
                $rule = self::build($moduleKey, $definition);

                if (isset($this->rules[$rule->key])) {
                    throw new LogicException("Rule [{$rule->key}] is defined twice.");
                }

                if (($error = $validator->validate($rule, $rule->default)) !== null) {
                    throw new LogicException("Rule [{$rule->key}] default does not match its schema: {$error}");
                }

                $this->rules[$rule->key] = $rule;
            }
        }

        uasort($this->rules, fn (RuleDefinition $a, RuleDefinition $b) => [$a->moduleKey, $a->category, $a->sortOrder, $a->key] <=> [$b->moduleKey, $b->category, $b->sortOrder, $b->key]);
    }

    public static function fromModules(ModuleRegistry $modules, RuleValueValidator $validator): self
    {
        $rulesByModule = [self::CORE_MODULE => require __DIR__.'/core-rules.php'];

        foreach ($modules->all() as $key => $module) {
            $rulesByModule[$key] = $module->rules;
        }

        return new self($rulesByModule, $validator);
    }

    /**
     * @return array<string, RuleDefinition>
     */
    public function all(): array
    {
        return $this->rules;
    }

    public function has(string $key): bool
    {
        return isset($this->rules[$key]);
    }

    public function get(string $key): RuleDefinition
    {
        return $this->rules[$key] ?? throw RuleException::unknownRule();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function build(string $moduleKey, array $definition): RuleDefinition
    {
        $key = $definition['key'] ?? '';

        if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.]*$/', $key)) {
            throw new LogicException("Rule key [{$key}] must look like module.rule_name.");
        }

        if ($moduleKey !== self::CORE_MODULE && ! str_starts_with($key, $moduleKey.'.')) {
            throw new LogicException("Rule [{$key}] must start with its module key \"{$moduleKey}.\".");
        }

        $type = RuleType::tryFrom($definition['type'] ?? '')
            ?? throw new LogicException("Rule [{$key}] has an unknown type.");

        try {
            $levels = array_map(fn (string $level) => RuleScope::from($level), $definition['overridable_levels'] ?? []);
        } catch (\ValueError) {
            throw new LogicException("Rule [{$key}] has an unknown overridable level.");
        }

        if ($levels === []) {
            throw new LogicException("Rule [{$key}] needs at least one overridable level.");
        }

        foreach (['label', 'description'] as $field) {
            if (! is_string($definition[$field] ?? null)) {
                throw new InvalidArgumentException("Rule [{$key}] needs a {$field} translation key.");
            }
        }

        if ($type === RuleType::Enum && ! isset($definition['schema']['enum'])) {
            throw new LogicException("Enum rule [{$key}] must list its options in schema.enum.");
        }

        return new RuleDefinition(
            key: $key,
            moduleKey: $moduleKey,
            type: $type,
            schema: $definition['schema'] ?? [],
            default: $definition['default'] ?? null,
            nullable: (bool) ($definition['nullable'] ?? false),
            label: $definition['label'],
            description: $definition['description'],
            overridableLevels: $levels,
            editPermission: $definition['edit_permission'] ?? 'rules.edit.'.$moduleKey,
            requiresApproval: (bool) ($definition['requires_approval'] ?? false),
            sensitive: (bool) ($definition['sensitive'] ?? false),
            countrySpecific: (bool) ($definition['country_specific'] ?? false),
            category: $definition['category'] ?? 'general',
            sortOrder: (int) ($definition['sort_order'] ?? 0),
        );
    }
}
