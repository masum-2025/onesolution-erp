<?php

namespace App\Platform\Tenancy\Models;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Exceptions\OrganizationChangeForbidden;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node of a client's tree: group > company > branch > department.
 *
 * Tree columns (partner_id, parent_id, root_id, path, depth, type) are not
 * mass assignable. They are written only by CreateOrganization and
 * HierarchyService::move(); any other change to them throws.
 */
#[Fillable([
    'name', 'sector_key', 'country_code', 'default_locale',
    'timezone', 'currency_code', 'region', 'status', 'settings',
])]
#[UseFactory(OrganizationFactory::class)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUlids;

    /** Columns that describe the organization's place in the tree. */
    public const TREE_COLUMNS = ['parent_id', 'root_id', 'path', 'depth', 'type'];

    private static bool $treeWritesAllowed = false;

    protected static function booted(): void
    {
        static::updating(function (Organization $organization) {
            if ($organization->isDirty('partner_id')) {
                throw new OrganizationChangeForbidden;
            }

            if (! self::$treeWritesAllowed && $organization->isDirty(self::TREE_COLUMNS)) {
                throw new OrganizationChangeForbidden;
            }

            $organization->version = (int) $organization->getOriginal('version') + 1;
        });
    }

    /**
     * Run a callback that is allowed to rewrite tree columns (a move, or a
     * personal workspace becoming a company).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function allowingTreeWrites(callable $callback): mixed
    {
        self::$treeWritesAllowed = true;

        try {
            return $callback();
        } finally {
            self::$treeWritesAllowed = false;
        }
    }

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'status' => OrganizationStatus::class,
            'name' => 'array',
            'settings' => 'array',
            'depth' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * Only organizations the current context may see: same partner, and
     * inside the context's visible subtree.
     *
     * @param  Builder<Organization>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, CurrentContext $context): void
    {
        $query->where($this->qualifyColumn('partner_id'), $context->partner()->getKey());

        if ($context->includesDescendants()) {
            $query->where($this->qualifyColumn('path'), 'like', $context->visiblePath().'%');
        } else {
            $query->where($this->qualifyColumn('path'), $context->visiblePath());
        }
    }

    /**
     * This organization and everything below it, within the same partner.
     *
     * @param  Builder<Organization>  $query
     */
    #[Scope]
    protected function subtreeOf(Builder $query, Organization $organization): void
    {
        $query->where($this->qualifyColumn('partner_id'), $organization->partner_id)
            ->where($this->qualifyColumn('path'), 'like', $organization->path.'%');
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_id');
    }

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * Ancestor ids from the root down to the parent, read from the path.
     *
     * @return list<string>
     */
    public function ancestorIds(): array
    {
        $ids = array_values(array_filter(explode('/', (string) $this->path)));
        array_pop($ids);

        return $ids;
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationStatus::Active;
    }

    /**
     * Name in the requested (or current) locale, falling back to the app
     * fallback locale, then to any translation that exists.
     */
    public function displayName(?string $locale = null): string
    {
        $names = (array) $this->name;
        $locale ??= app()->getLocale();

        return (string) ($names[$locale]
            ?? $names[config('app.fallback_locale')]
            ?? reset($names)
            ?: '');
    }
}
