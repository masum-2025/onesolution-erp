<?php

namespace Modules\Hrm\Services;

use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Modules\Hrm\Exceptions\HrmException;

/**
 * Where people can work: a company (or a personal workspace) and its
 * branches and departments; never a group on its own.
 */
class Units
{
    public function companyOf(Organization $unit): Organization
    {
        if (in_array($unit->type, [OrganizationType::Company, OrganizationType::Personal], true)) {
            return $unit;
        }

        if ($unit->type === OrganizationType::Group) {
            throw HrmException::notCompanyUnit();
        }

        return Organization::query()
            ->whereKey($unit->ancestorIds())
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Personal->value])
            ->orderByDesc('depth')
            ->first() ?? throw HrmException::notCompanyUnit();
    }

    /** Nobody is hired into, or moved to, a closed (suspended or archived) branch or department. */
    public function assertOpen(Organization $unit): void
    {
        if ($unit->status !== OrganizationStatus::Active) {
            throw HrmException::unitClosed();
        }
    }

    /**
     * Where a unit sits for people: its branch (or the company) and its
     * department, if the unit is one.
     *
     * @return array{place: string, department: string|null}
     */
    public function placeOf(Organization $unit): array
    {
        return $unit->type === OrganizationType::Department && $unit->parent_id !== null
            ? ['place' => $unit->parent_id, 'department' => $unit->getKey()]
            : ['place' => $unit->getKey(), 'department' => null];
    }

    /**
     * The unit's short code for employee codes: its settings "code", else the
     * first letters of its name.
     */
    public function codeOf(Organization $unit): string
    {
        $code = $unit->settings['code'] ?? null;
        if (is_string($code) && $code !== '') {
            return strtoupper($code);
        }

        $name = $unit->texts('name')['en'] ?? $unit->displayName();
        $letters = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $name));

        return substr($letters, 0, 3) ?: 'U';
    }
}
