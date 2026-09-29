<?php

namespace App\Platform\Offline;

use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Offline\Contracts\SyncableRecords;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The kinds of records that can be changed offline, from module manifests
 * ("sync_records" => [ProviderClass::class, ...]). A kind works in an
 * organization only while its module and offline_mode are on there.
 */
class SyncRecords
{
    /** @var array<string, array{module: string, provider: class-string<SyncableRecords>}> */
    private array $kinds = [];

    private bool $loaded = false;

    public function __construct(
        private Container $container,
        private ModuleRegistry $modules,
        private ModuleResolver $resolver,
    ) {}

    /**
     * @param  class-string<SyncableRecords>  $provider
     */
    public function register(string $moduleKey, string $provider): void
    {
        $key = $this->container->make($provider)->key();
        if (! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key)) {
            throw new LogicException("Offline record kind [{$key}] must look like module.kind.");
        }

        $this->kinds[$key] = ['module' => $moduleKey, 'provider' => $provider];
    }

    public function has(string $key): bool
    {
        $this->load();

        return isset($this->kinds[$key]);
    }

    public function provider(string $key): SyncableRecords
    {
        $this->load();

        return $this->container->make($this->kinds[$key]['provider'] ?? throw new LogicException("Unknown offline record kind [{$key}]."));
    }

    /**
     * Kinds usable offline in this organization now.
     *
     * @return list<string>
     */
    public function available(Organization $organization): array
    {
        $this->load();

        if (! $this->resolver->isEnabled('offline_mode', $organization)) {
            return [];
        }

        return array_values(array_keys(array_filter(
            $this->kinds,
            fn (array $kind) => $this->resolver->isEnabled($kind['module'], $organization),
        )));
    }

    public function usable(string $key, Organization $organization): bool
    {
        return in_array($key, $this->available($organization), true);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        foreach ($this->modules->all() as $module) {
            foreach ($module->syncRecords as $provider) {
                $this->register($module->key, $provider);
            }
        }
    }
}
