<?php

namespace App\Platform\Access\Exceptions;

use App\Platform\Access\PermissionCatalog;
use App\Platform\Tenancy\Exceptions\TenancyException;

class AccessException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'access.errors.'.$this->errorCode;
    }

    public static function roleNotFound(): self
    {
        return new self('role_not_found', 404);
    }

    public static function templateNotFound(): self
    {
        return new self('template_not_found', 422);
    }

    /**
     * Anti-escalation: nobody grants what they do not hold.
     *
     * @param  list<string>  $permissions
     */
    public static function cannotGrant(array $permissions): self
    {
        return new self('cannot_grant', 403, ['permissions' => self::labels($permissions)], ['permissions' => array_values($permissions)]);
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function moduleDisabled(array $permissions): self
    {
        return new self('module_disabled', 422, ['permissions' => self::labels($permissions)], ['permissions' => array_values($permissions)]);
    }

    /**
     * @param  list<array{first: string, second: string}>  $pairs
     */
    public static function separationOfDuties(array $pairs): self
    {
        $pair = $pairs[0];

        return new self('separation_of_duties', 422, [
            'first' => self::labels([$pair['first']]),
            'second' => self::labels([$pair['second']]),
        ], ['pairs' => array_values($pairs)]);
    }

    /**
     * @param  list<array{first: string, second: string}>  $pairs
     */
    public static function separationOfDutiesForMembers(array $pairs, int $members): self
    {
        $pair = $pairs[0];

        return new self('separation_of_duties_members', 422, [
            'first' => self::labels([$pair['first']]),
            'second' => self::labels([$pair['second']]),
            'count' => (string) $members,
        ], ['pairs' => array_values($pairs), 'members' => $members]);
    }

    public static function roleInUse(int $members): self
    {
        return new self('role_in_use', 422, ['count' => (string) $members], ['members' => $members]);
    }

    public static function versionConflict(int $current): self
    {
        return new self('version_conflict', 409, [], ['current_version' => $current]);
    }

    public static function roleNotUsable(): self
    {
        return new self('role_not_usable', 422);
    }

    public static function portalMembership(): self
    {
        return new self('portal_membership', 422);
    }

    public static function ownRoles(): self
    {
        return new self('own_roles', 422);
    }

    public static function ownerOnly(): self
    {
        return new self('owner_only', 403);
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function labels(array $permissions): string
    {
        $catalog = app(PermissionCatalog::class);

        return implode(', ', array_map(
            fn (string $key) => $catalog->has($key) ? $catalog->get($key)->label() : $key,
            $permissions,
        ));
    }
}
