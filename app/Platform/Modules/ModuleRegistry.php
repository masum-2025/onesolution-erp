<?php

namespace App\Platform\Modules;

use App\Platform\Modules\Exceptions\InvalidModuleManifest;
use App\Platform\Modules\Exceptions\ModuleException;

/**
 * Every installed module, validated, in dependency order (a module always
 * comes after the modules it requires).
 */
final class ModuleRegistry
{
    /**
     * @param  array<string, ModuleDefinition>  $modules  Keyed by module key, dependency-ordered.
     */
    private function __construct(private array $modules) {}

    /**
     * @param  list<array<string, mixed>>  $manifests
     * @param  list<string>  $knownPlans
     */
    public static function fromManifests(array $manifests, array $knownPlans): self
    {
        $definitions = [];

        foreach ($manifests as $manifest) {
            self::validate($manifest, $knownPlans);
            $key = $manifest['key'];

            if (isset($definitions[$key])) {
                throw InvalidModuleManifest::because($key, 'duplicate key');
            }

            $definitions[$key] = ModuleDefinition::fromManifest($manifest);
        }

        foreach ($definitions as $definition) {
            foreach ($definition->requires as $required) {
                if (! isset($definitions[$required])) {
                    throw InvalidModuleManifest::because($definition->key, "requires unknown module [{$required}]");
                }
            }
        }

        return new self(self::sortByDependencies($definitions));
    }

    /**
     * @return array<string, ModuleDefinition>
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->modules);
    }

    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    public function get(string $key): ModuleDefinition
    {
        return $this->modules[$key] ?? throw ModuleException::notFound();
    }

    /**
     * Everything the module needs, directly or indirectly, dependencies first.
     *
     * @return list<string>
     */
    public function dependenciesOf(string $key): array
    {
        $needed = [];
        $stack = $this->get($key)->requires;

        while ($stack !== []) {
            $current = array_pop($stack);

            if (! isset($needed[$current])) {
                $needed[$current] = true;
                array_push($stack, ...$this->get($current)->requires);
            }
        }

        return array_values(array_filter($this->keys(), fn (string $k) => isset($needed[$k])));
    }

    /**
     * Every module that needs this one, directly or indirectly, in dependency order.
     *
     * @return list<string>
     */
    public function dependentsOf(string $key): array
    {
        $this->get($key);

        return array_values(array_filter(
            $this->keys(),
            fn (string $candidate) => $candidate !== $key && in_array($key, $this->dependenciesOf($candidate), true),
        ));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $knownPlans
     */
    private static function validate(array $manifest, array $knownPlans): void
    {
        $key = $manifest['key'] ?? null;

        if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{1,49}$/', $key)) {
            throw InvalidModuleManifest::because((string) json_encode($key), 'key must be snake_case, 2-50 characters');
        }

        foreach (['name', 'version', 'category'] as $field) {
            if (! is_string($manifest[$field] ?? null) || $manifest[$field] === '') {
                throw InvalidModuleManifest::because($key, "missing {$field}");
            }
        }

        foreach (['requires', 'sectors', 'plans', 'permissions', 'rules', 'menu', 'events', 'separation_of_duties'] as $field) {
            if (isset($manifest[$field]) && ! array_is_list($manifest[$field])) {
                throw InvalidModuleManifest::because($key, "{$field} must be a list");
            }
        }

        if (in_array($key, $manifest['requires'] ?? [], true)) {
            throw InvalidModuleManifest::because($key, 'a module cannot require itself');
        }

        if (empty($manifest['sectors']) || empty($manifest['plans'])) {
            throw InvalidModuleManifest::because($key, "sectors and plans must not be empty (use ['*'] for all)");
        }

        foreach ($manifest['plans'] as $plan) {
            if ($plan !== '*' && ! in_array($plan, $knownPlans, true)) {
                throw InvalidModuleManifest::because($key, "unknown plan [{$plan}]");
            }
        }

        foreach ($manifest['permissions'] ?? [] as $permission) {
            if (! is_string($permission) || ! str_starts_with($permission, $key.'.')) {
                throw InvalidModuleManifest::because($key, "permission [{$permission}] must start with \"{$key}.\"");
            }
        }

        foreach ($manifest['separation_of_duties'] ?? [] as $pair) {
            $valid = is_array($pair) && count($pair) === 2 && $pair[0] !== $pair[1]
                && array_filter($pair, fn ($permission) => ! is_string($permission) || ! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.]*$/', $permission)) === [];

            if (! $valid || ! array_intersect($pair, $manifest['permissions'] ?? [])) {
                throw InvalidModuleManifest::because($key, 'separation_of_duties entries must be two different permissions, at least one of this module');
            }
        }

        foreach (['quick_actions', 'attention'] as $field) {
            if (isset($manifest[$field]) && ! array_is_list($manifest[$field])) {
                throw InvalidModuleManifest::because($key, "{$field} must be a list");
            }
        }

        foreach ($manifest['menu'] ?? [] as $item) {
            foreach (['key', 'label', 'route', 'order'] as $field) {
                if (! isset($item[$field])) {
                    throw InvalidModuleManifest::because($key, "menu item is missing {$field}");
                }
            }

            if (isset($item['section']) && ! (is_string($item['section']) && preg_match('/^[a-z][a-z0-9_]{1,49}$/', $item['section']))) {
                throw InvalidModuleManifest::because($key, 'menu section must be snake_case');
            }

            self::validatePermission($manifest, $item['permission'] ?? null, 'menu item', required: false);

            if (isset($item['children']) && ! array_is_list($item['children'])) {
                throw InvalidModuleManifest::because($key, 'menu children must be a list');
            }

            foreach ($item['children'] ?? [] as $child) {
                foreach (['key', 'label', 'route'] as $field) {
                    if (! isset($child[$field])) {
                        throw InvalidModuleManifest::because($key, "menu child is missing {$field}");
                    }
                }
                self::validatePermission($manifest, $child['permission'] ?? null, 'menu child', required: false);
            }
        }

        // Creating something always needs a permission of the module itself.
        foreach ($manifest['quick_actions'] ?? [] as $action) {
            foreach (['key', 'label', 'route'] as $field) {
                if (! isset($action[$field])) {
                    throw InvalidModuleManifest::because($key, "quick action is missing {$field}");
                }
            }
            self::validatePermission($manifest, $action['permission'] ?? null, 'quick action', required: true);
        }

        foreach ($manifest['attention'] ?? [] as $class) {
            if (! is_string($class) || $class === '') {
                throw InvalidModuleManifest::because($key, 'attention entries must be class names');
            }
        }
    }

    /**
     * A permission named by a menu entry or quick action must be one the
     * module declares, so a typo cannot silently hide (or show) an entry.
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function validatePermission(array $manifest, mixed $permission, string $what, bool $required): void
    {
        $key = $manifest['key'];

        if ($permission === null && ! $required) {
            return;
        }

        if (! is_string($permission) || ! in_array($permission, $manifest['permissions'] ?? [], true)) {
            throw InvalidModuleManifest::because($key, "{$what} permission [".(is_string($permission) ? $permission : '?')."] is not one of the module's permissions");
        }
    }

    /**
     * Depth-first topological sort; reports the first dependency cycle found.
     *
     * @param  array<string, ModuleDefinition>  $definitions
     * @return array<string, ModuleDefinition>
     */
    private static function sortByDependencies(array $definitions): array
    {
        ksort($definitions);

        $sorted = [];
        $visiting = [];

        $visit = function (string $key, array $trail) use (&$visit, &$sorted, &$visiting, $definitions): void {
            if (isset($sorted[$key])) {
                return;
            }

            if (isset($visiting[$key])) {
                throw InvalidModuleManifest::because($key, 'dependency cycle: '.implode(' -> ', [...$trail, $key]));
            }

            $visiting[$key] = true;

            foreach ($definitions[$key]->requires as $required) {
                $visit($required, [...$trail, $key]);
            }

            unset($visiting[$key]);
            $sorted[$key] = $definitions[$key];
        };

        foreach (array_keys($definitions) as $key) {
            $visit($key, []);
        }

        return $sorted;
    }
}
