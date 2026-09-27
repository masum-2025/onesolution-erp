<?php

namespace App\Platform\Payments;

use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Payments\Contracts\CollectableProvider;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * What customers can pay a client for online, from module manifests
 * ("payment_collectables" => [ProviderClass::class, ...]). A kind is usable
 * in an organization only while online_payments and its own module are on there.
 */
class PaymentCollectables
{
    /** @var array<string, array{module: string, provider: class-string<CollectableProvider>}> */
    private array $kinds = [];

    private bool $loaded = false;

    public function __construct(
        private Container $container,
        private ModuleRegistry $modules,
        private ModuleResolver $resolver,
    ) {}

    /**
     * @param  class-string<CollectableProvider>  $provider
     */
    public function register(string $moduleKey, string $provider): void
    {
        $key = $this->container->make($provider)->key();
        if (! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key)) {
            throw new LogicException("Payment kind [{$key}] must look like module.kind.");
        }

        $this->kinds[$key] = ['module' => $moduleKey, 'provider' => $provider];
    }

    public function has(string $key): bool
    {
        $this->load();

        return isset($this->kinds[$key]);
    }

    public function provider(string $key): CollectableProvider
    {
        $this->load();

        return $this->container->make($this->kinds[$key]['provider'] ?? throw new LogicException("Unknown payment kind [{$key}]."));
    }

    public function usable(string $key, Organization $organization): bool
    {
        $this->load();

        return isset($this->kinds[$key])
            && $this->resolver->resolve('online_payments', $organization)->enabled
            && $this->resolver->resolve($this->kinds[$key]['module'], $organization)->enabled;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        foreach ($this->modules->all() as $module) {
            foreach ($module->paymentCollectables as $provider) {
                $this->register($module->key, $provider);
            }
        }
    }
}
