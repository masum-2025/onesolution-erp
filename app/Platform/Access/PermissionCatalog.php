<?php

namespace App\Platform\Access;

use App\Platform\Modules\ModuleRegistry;
use App\Platform\Rules\RuleCatalog;

/**
 * Every permission that exists: platform core permissions, each module's
 * manifest permissions, and "rules.edit.{module}" for modules with rules.
 * A new module adds permissions through its manifest only.
 */
final class PermissionCatalog
{
    public const CORE_MODULE = 'core';

    /** @var array<string, PermissionDefinition> */
    private array $permissions = [];

    public function __construct(ModuleRegistry $modules, RuleCatalog $rules)
    {
        foreach (require __DIR__.'/core-permissions.php' as $entry) {
            $this->add(new PermissionDefinition(
                key: $entry['key'],
                moduleKey: self::CORE_MODULE,
                labelKey: 'access.permissions.'.str_replace('.', '_', $entry['key']),
                group: $entry['group'],
            ));
        }

        foreach ($modules->all() as $moduleKey => $module) {
            foreach ($module->permissions as $key) {
                $this->add(new PermissionDefinition(
                    key: $key,
                    moduleKey: $moduleKey,
                    labelKey: $moduleKey.'::permissions.'.substr($key, strlen($moduleKey) + 1),
                    group: $moduleKey,
                ));
            }
        }

        // Editing a module's rules is its own permission (RuleDefinition::$editPermission).
        foreach ($rules->all() as $rule) {
            if (isset($this->permissions[$rule->editPermission])) {
                continue;
            }

            $moduleName = $rule->moduleKey === RuleCatalog::CORE_MODULE ? 'rules.core_module_name' : $modules->get($rule->moduleKey)->name;

            $this->add(new PermissionDefinition(
                key: $rule->editPermission,
                moduleKey: $rule->moduleKey === RuleCatalog::CORE_MODULE ? self::CORE_MODULE : $rule->moduleKey,
                labelKey: 'access.permissions.rules_edit',
                group: $rule->moduleKey === RuleCatalog::CORE_MODULE ? 'setup' : $rule->moduleKey,
                labelReplace: ['module' => $moduleName],
            ));
        }
    }

    private function add(PermissionDefinition $permission): void
    {
        if (isset($this->permissions[$permission->key])) {
            throw new \LogicException("Permission [{$permission->key}] is declared twice.");
        }

        $this->permissions[$permission->key] = $permission;
    }

    /**
     * @return array<string, PermissionDefinition>
     */
    public function all(): array
    {
        return $this->permissions;
    }

    public function has(string $key): bool
    {
        return isset($this->permissions[$key]);
    }

    public function get(string $key): PermissionDefinition
    {
        return $this->permissions[$key] ?? throw new \InvalidArgumentException("Unknown permission [{$key}].");
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->permissions);
    }

    /**
     * Expand patterns used by role templates: "payroll.*", "*.view", "*",
     * and "!pattern" to remove. Unknown keys are ignored.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public function expand(array $patterns): array
    {
        $matches = fn (string $pattern) => array_values(array_filter(
            $this->keys(),
            fn (string $key) => fnmatch($pattern, $key, FNM_NOESCAPE),
        ));

        $keys = [];
        foreach ($patterns as $pattern) {
            if (! str_starts_with($pattern, '!')) {
                $keys = [...$keys, ...$matches($pattern)];
            }
        }
        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '!')) {
                $keys = array_diff($keys, $matches(substr($pattern, 1)));
            }
        }

        return array_values(array_unique($keys));
    }
}
