<?php

namespace App\Platform\Packaging;

use InvalidArgumentException;
use LogicException;

/**
 * Every plan, read from the data file (not the database) so the module
 * registry can use it at boot. `packaging:sync` mirrors it into tables.
 */
final class PlanCatalog
{
    public const PERIODS = ['monthly', 'yearly'];

    public const BUSINESS = 'business';

    public const PERSONAL = 'personal';

    /** @var array<string, PlanDefinition> */
    private array $plans = [];

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function __construct(array $entries)
    {
        foreach (array_values($entries) as $order => $entry) {
            $key = $entry['key'] ?? null;

            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{1,49}$/', $key) || isset($this->plans[$key])) {
                throw new LogicException('Every plan needs a unique key like "business".');
            }

            foreach ($entry['prices'] ?? [] as $price) {
                if (! is_int($price['amount_minor'] ?? null) || $price['amount_minor'] < 0
                    || ! preg_match('/^[A-Z]{3}$/', (string) ($price['currency'] ?? ''))
                    || ! in_array($price['period'] ?? null, self::PERIODS, true)) {
                    throw new LogicException("Plan [{$key}] has an invalid price (integer minor units, ISO currency, monthly|yearly).");
                }
            }

            $audience = $entry['audience'] ?? self::BUSINESS;
            if (! in_array($audience, [self::BUSINESS, self::PERSONAL], true)) {
                throw new LogicException("Plan [{$key}] has an unknown audience (business|personal).");
            }

            $this->plans[$key] = new PlanDefinition(
                key: $key,
                public: (bool) ($entry['public'] ?? true),
                modules: array_values($entry['modules'] ?? []),
                prices: array_values($entry['prices'] ?? []),
                sortOrder: $order,
                audience: $audience,
            );
        }
    }

    public static function fromDataFile(): self
    {
        return new self(require database_path('seeders/data/plans.php'));
    }

    /**
     * @return array<string, PlanDefinition>
     */
    public function all(): array
    {
        return $this->plans;
    }

    /**
     * @return list<string>
     */
    public function keys(?string $audience = null): array
    {
        return $audience === null
            ? array_keys($this->plans)
            : array_keys(array_filter($this->plans, fn (PlanDefinition $plan) => $plan->audience === $audience));
    }

    public function has(?string $key): bool
    {
        return $key !== null && isset($this->plans[$key]);
    }

    public function get(string $key): PlanDefinition
    {
        return $this->plans[$key] ?? throw new InvalidArgumentException("Unknown plan [{$key}].");
    }

    /**
     * Whether a plan includes a module. An unknown plan includes nothing.
     */
    public function includes(?string $planKey, string $moduleKey): bool
    {
        return $this->has($planKey) && $this->plans[$planKey]->includes($moduleKey);
    }
}
