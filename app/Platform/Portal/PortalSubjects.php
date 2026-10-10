<?php

namespace App\Platform\Portal;

use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Portal\Contracts\PortalSubjectProvider;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The kinds of records portals can show, from module manifests
 * ("portal_subjects" => [ProviderClass::class, ...]). A kind is usable in an
 * organization only while its module is on there, and the portal itself.
 */
class PortalSubjects
{
    /** @var array<string, array{module: string, provider: class-string<PortalSubjectProvider>}> */
    private array $kinds = [];

    private bool $loaded = false;

    public function __construct(
        private Container $container,
        private ModuleRegistry $modules,
        private ModuleResolver $resolver,
    ) {}

    /**
     * @param  class-string<PortalSubjectProvider>  $provider
     */
    public function register(string $moduleKey, string $provider): void
    {
        $key = $this->container->make($provider)->key();
        if (! str_contains($key, '.') || ! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key)) {
            throw new LogicException("Portal record kind [{$key}] must look like module.kind.");
        }

        $this->kinds[$key] = ['module' => $moduleKey, 'provider' => $provider];
    }

    public function has(string $key): bool
    {
        $this->load();

        return isset($this->kinds[$key]);
    }

    public function provider(string $key): PortalSubjectProvider
    {
        $this->load();

        return $this->container->make($this->kinds[$key]['provider'] ?? throw new LogicException("Unknown portal record kind [{$key}]."));
    }

    /**
     * Kinds usable in this organization now.
     *
     * @return list<string>
     */
    public function available(Organization $organization): array
    {
        $this->load();

        if (! $this->resolver->resolve('client_portal', $organization)->enabled) {
            return [];
        }

        return array_values(array_keys(array_filter(
            $this->kinds,
            fn (array $kind) => $this->resolver->resolve($kind['module'], $organization)->enabled,
        )));
    }

    /**
     * Portal screens other modules offer for a record kind (an employee's
     * payslips, their attendance), from modules on in this organization.
     * "{record}" in a route becomes the member's record (link) id, so a
     * parent with two children opens the page of the right one.
     *
     * @return list<array{label: string, to: string}>
     */
    public function pages(string $key, Organization $organization, ?string $record = null): array
    {
        $pages = [];
        foreach ($this->modules->all() as $module) {
            foreach ($module->portalPages as $page) {
                if ($page['subject'] === $key && $this->resolver->resolve($module->key, $organization)->enabled) {
                    $pages[] = ['label' => __($page['label']), 'to' => str_replace('{record}', (string) $record, $page['route'])];
                }
            }
        }

        return $pages;
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
            foreach ($module->portalSubjects as $provider) {
                $this->register($module->key, $provider);
            }
        }
    }
}
