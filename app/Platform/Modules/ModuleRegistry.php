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

        self::validateDashboardAndSettings($manifest);
        self::validateNotifications($manifest);
        self::validateLedgerAccounts($manifest);
    }

    /** Kinds of account a ledger account may be (Accounting's account types). */
    public const LEDGER_ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /**
     * Accounts a module posts to (e.g. "payroll.salary_expense"): each client
     * maps them to accounts of its own chart; no account is hardcoded.
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function validateLedgerAccounts(array $manifest): void
    {
        $key = $manifest['key'];

        if (isset($manifest['ledger_accounts']) && (! is_array($manifest['ledger_accounts']) || ($manifest['ledger_accounts'] !== [] && array_is_list($manifest['ledger_accounts'])))) {
            throw InvalidModuleManifest::because($key, 'ledger_accounts must be keyed by posting key');
        }

        foreach ($manifest['ledger_accounts'] ?? [] as $name => $definition) {
            $valid = is_string($name) && str_starts_with($name, $key.'.') && preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $name)
                && is_string($definition['label'] ?? null) && $definition['label'] !== ''
                && in_array($definition['type'] ?? null, self::LEDGER_ACCOUNT_TYPES, true)
                // Optional: a clearing account another document settles (only bills today).
                && in_array($definition['cleared_by'] ?? null, [null, 'bills'], true);

            if (! $valid) {
                throw InvalidModuleManifest::because($key, "ledger account [{$name}] needs a \"{$key}.\" key, a label and a type (asset, liability, equity, income or expense)");
            }
        }
    }

    /**
     * Messages a module sends: keyed "{module}.{name}", with the channels,
     * placeholders, audience and screen of a platform notification.
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function validateNotifications(array $manifest): void
    {
        $key = $manifest['key'];

        if (isset($manifest['notifications']) && (! is_array($manifest['notifications']) || ($manifest['notifications'] !== [] && array_is_list($manifest['notifications'])))) {
            throw InvalidModuleManifest::because($key, 'notifications must be keyed by notification key');
        }

        foreach ($manifest['notifications'] ?? [] as $name => $definition) {
            $valid = is_string($name) && str_starts_with($name, $key.'.') && preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $name)
                && is_array($definition['channels'] ?? null) && $definition['channels'] !== [] && array_diff($definition['channels'], ['mail', 'sms']) === []
                && is_array($definition['placeholders'] ?? null)
                && in_array($definition['audience'] ?? null, ['client', 'partner', 'person'], true)
                && is_string($definition['path'] ?? null) && str_starts_with($definition['path'], '/');

            if (! $valid) {
                throw InvalidModuleManifest::because($key, "notification [{$name}] needs a \"{$key}.\" key, channels (mail/sms), placeholders, audience and path");
            }
        }
    }

    /** Widget types the app can draw (resources/js/components/dashboard). */
    public const WIDGET_TYPES = ['stat', 'bars', 'list'];

    /**
     * Every module has a dashboard and a settings page. The manifest must say
     * what is on them, even if that is nothing yet (an empty list).
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function validateDashboardAndSettings(array $manifest): void
    {
        $key = $manifest['key'];

        if (! is_array($manifest['dashboard']['widgets'] ?? null) || ! array_is_list($manifest['dashboard']['widgets'])) {
            throw InvalidModuleManifest::because($key, 'dashboard.widgets is required (a list, may be empty)');
        }

        if (! is_array($manifest['settings']['pages'] ?? null) || ! array_is_list($manifest['settings']['pages'])) {
            throw InvalidModuleManifest::because($key, 'settings.pages is required (a list, may be empty)');
        }

        $seen = [];
        foreach ($manifest['dashboard']['widgets'] as $widget) {
            foreach (['key', 'label', 'type', 'provider'] as $field) {
                if (! is_string($widget[$field] ?? null) || $widget[$field] === '') {
                    throw InvalidModuleManifest::because($key, "dashboard widget is missing {$field}");
                }
            }
            if (! preg_match('/^[a-z][a-z0-9_]{1,49}$/', $widget['key']) || isset($seen[$widget['key']])) {
                throw InvalidModuleManifest::because($key, "dashboard widget key [{$widget['key']}] must be snake_case and unique");
            }
            if (! in_array($widget['type'], self::WIDGET_TYPES, true)) {
                throw InvalidModuleManifest::because($key, "dashboard widget type [{$widget['type']}] is unknown");
            }
            if (isset($widget['size']) && ! in_array($widget['size'], [1, 2, 3], true)) {
                throw InvalidModuleManifest::because($key, 'dashboard widget size must be 1, 2 or 3');
            }
            // A widget shows the module's data: reading it needs a permission of the module.
            self::validatePermission($manifest, $widget['permission'] ?? null, 'dashboard widget', required: true);
            $seen[$widget['key']] = true;
        }

        foreach ($manifest['settings']['pages'] as $page) {
            foreach (['key', 'label', 'route'] as $field) {
                if (! isset($page[$field])) {
                    throw InvalidModuleManifest::because($key, "settings page is missing {$field}");
                }
            }
            self::validatePermission($manifest, $page['permission'] ?? null, 'settings page', required: false);
        }

        // A portal screen opens from a record kind ("hrm.employee") at a path under /portal/.
        foreach ($manifest['portal_pages'] ?? [] as $page) {
            if (! is_string($page['subject'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $page['subject'])
                || ! is_string($page['label'] ?? null) || ! is_string($page['route'] ?? null) || ! str_starts_with($page['route'], '/portal/')) {
                throw InvalidModuleManifest::because($key, 'portal page needs a subject (module.kind), a label and a route under /portal/');
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
