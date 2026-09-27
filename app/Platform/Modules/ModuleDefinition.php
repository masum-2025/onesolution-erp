<?php

namespace App\Platform\Modules;

/**
 * A module as declared by its manifest (Modules/{Name}/manifest.php).
 */
final readonly class ModuleDefinition
{
    /**
     * @param  list<string>  $requires  Direct dependencies (module keys).
     * @param  list<string>  $sectors  Sector keys, or ['*'].
     * @param  list<string>  $plans  Plan keys, or ['*'].
     * @param  list<string>  $permissions
     * @param  list<array<string, mixed>>  $rules  Rule definitions (Phase 3).
     * @param  list<array{key: string, label: string, route: string, icon?: string, order: int}>  $menu
     * @param  list<string>  $events
     * @param  list<array{0: string, 1: string}>  $separationOfDuties  Permission pairs one person may not hold together.
     * @param  list<class-string>  $portalSubjects  PortalSubjectProvider classes.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $version,
        public string $category,
        public array $requires,
        public array $sectors,
        public array $plans,
        public array $permissions,
        public array $rules,
        public array $menu,
        public array $events,
        public bool $isCore,
        public bool $requiresConsent,
        public array $separationOfDuties = [],
        // Kinds of records a portal may show (Phase 5C-4): PortalSubjectProvider classes.
        public array $portalSubjects = [],
    ) {}

    /**
     * @param  array<string, mixed>  $manifest  Already validated by ModuleRegistry.
     */
    public static function fromManifest(array $manifest): self
    {
        return new self(
            key: $manifest['key'],
            name: $manifest['name'],
            description: $manifest['description'] ?? '',
            version: $manifest['version'],
            category: $manifest['category'],
            requires: array_values($manifest['requires'] ?? []),
            sectors: array_values($manifest['sectors']),
            plans: array_values($manifest['plans']),
            permissions: array_values($manifest['permissions'] ?? []),
            rules: array_values($manifest['rules'] ?? []),
            menu: array_values($manifest['menu'] ?? []),
            events: array_values($manifest['events'] ?? []),
            isCore: (bool) ($manifest['is_core'] ?? false),
            requiresConsent: (bool) ($manifest['requires_consent'] ?? false),
            separationOfDuties: array_values(array_map('array_values', $manifest['separation_of_duties'] ?? [])),
            portalSubjects: array_values($manifest['portal_subjects'] ?? []),
        );
    }

    /** Translated display name. */
    public function label(?string $locale = null): string
    {
        return __($this->name, [], $locale);
    }

    public function allowsPlan(?string $plan): bool
    {
        return in_array('*', $this->plans, true) || ($plan !== null && in_array($plan, $this->plans, true));
    }

    /** A null sector (e.g. a group above companies) is not restricted here. */
    public function allowsSector(?string $sector): bool
    {
        return $sector === null || in_array('*', $this->sectors, true) || in_array($sector, $this->sectors, true);
    }
}
