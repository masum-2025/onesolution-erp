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
     * @param  list<array{key: string, label: string, route: string, icon?: string, order: int, section?: string, permission?: string, children?: list<array{key: string, label: string, route: string, permission?: string}>}>  $menu
     * @param  list<string>  $events
     * @param  list<array{0: string, 1: string}>  $separationOfDuties  Permission pairs one person may not hold together.
     * @param  list<class-string>  $portalSubjects  PortalSubjectProvider classes.
     * @param  list<class-string>  $paymentCollectables  CollectableProvider classes.
     * @param  list<class-string>  $syncRecords  SyncableRecords classes.
     * @param  list<array{key: string, label: string, route: string, permission: string, icon?: string}>  $quickActions  Entries of the header "New" menu.
     * @param  list<class-string>  $attention  AttentionProvider classes (header bell).
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
        // What customers may pay the client for online (Phase 6): CollectableProvider classes.
        public array $paymentCollectables = [],
        // Records that can be changed offline and synced (Phase 7): SyncableRecords classes.
        public array $syncRecords = [],
        // Things a person can create from any screen (the header "New" menu).
        public array $quickActions = [],
        // Work waiting for someone, counted in the header bell: AttentionProvider classes.
        public array $attention = [],
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
            paymentCollectables: array_values($manifest['payment_collectables'] ?? []),
            syncRecords: array_values($manifest['sync_records'] ?? []),
            quickActions: array_values($manifest['quick_actions'] ?? []),
            attention: array_values($manifest['attention'] ?? []),
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
