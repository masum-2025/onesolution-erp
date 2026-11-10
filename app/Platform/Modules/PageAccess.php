<?php

namespace App\Platform\Modules;

use App\Platform\Access\AccessResolver;
use App\Platform\Access\Services\RoleService;
use App\Platform\Tenancy\Context\CurrentContext;

/**
 * FE-1: which business modules' screens the browser loads here, and which of
 * their menu pages this person may not open.
 *
 * The browser loads a module's route list only when the module is on here,
 * and leaves out every menu page whose permission (or sector) the person's
 * roles do not give: the same test as the menu (MenuController). Pages that
 * are not in a menu (an order's own page, "my leave") follow their module.
 * This only shapes the UI: every API call still checks permission, the
 * organization and the module.
 */
final class PageAccess
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleResolver $resolver,
        private AccessResolver $access,
        private RoleService $roles,
    ) {}

    /** Whether a menu entry (item, child or quick action) is for this person: its permission held, its sector matching. */
    public static function allows(array $entry, array $held, ?string $sector): bool
    {
        return (! isset($entry['permission']) || in_array($entry['permission'], $held, true))
            && (! isset($entry['sectors']) || ($sector !== null && in_array($sector, $entry['sectors'], true)));
    }

    /**
     * @return array{modules: list<string>, denied_pages: list<string>}
     */
    public function forContext(CurrentContext $context): array
    {
        if (! $context->hasOrganization()) {
            return ['modules' => [], 'denied_pages' => []];
        }
        $organization = $context->organization();
        $resolved = $this->resolver->resolveAll($organization);
        $held = $this->access->held();
        $sector = $this->roles->sectorOf($organization);
        $modules = [];
        $denied = [];
        $allowed = [];

        foreach ($this->registry->all() as $key => $module) {
            if (! $resolved[$key]->enabled) {
                continue;
            }
            $modules[] = $key;
            foreach ($module->menu as $item) {
                $itemAllowed = self::allows($item, $held, $sector);
                $children = $item['children'] ?? [];
                // An entry whose own address is also one of its children's: the child (with its permission) decides.
                $own = in_array($item['route'] ?? null, array_column($children, 'route'), true) ? [] : [$item];
                foreach ([...$own, ...$children] as $entry) {
                    if (! isset($entry['route'])) {
                        continue;
                    }
                    // A child of an entry the person may not see is not theirs either.
                    if ($itemAllowed && self::allows($entry, $held, $sector)) {
                        $allowed[$entry['route']] = true;
                    } else {
                        $denied[$entry['route']] = true;
                    }
                }
            }
        }

        // A page another entry gives them stays open.
        return ['modules' => $modules, 'denied_pages' => array_keys(array_diff_key($denied, $allowed))];
    }
}
