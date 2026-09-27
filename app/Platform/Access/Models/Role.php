<?php

namespace App\Platform\Access\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A role owned by one organization and usable there and in every unit below
 * it (a company's "Branch manager" serves all its branches). Not tenant-scoped
 * by a global scope because members below read roles of their ancestors;
 * every access goes through RoleService and visible-organization lookups.
 */
#[Table('roles')]
#[Fillable(['organization_id', 'key', 'name', 'description', 'template_key', 'version', 'created_by'])]
class Role extends Model
{
    use HasTranslatedTexts, HasUlids;

    /** @var list<string> Data labels in several languages. */
    public array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<MembershipRole, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(MembershipRole::class);
    }

    /**
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        return DB::table('role_permissions')
            ->where('role_id', $this->getKey())
            ->orderBy('permission_key')
            ->pluck('permission_key')
            ->all();
    }

    public function displayName(?string $locale = null): string
    {
        return $this->textIn('name', $locale) ?: $this->key;
    }

    public function displayDescription(?string $locale = null): ?string
    {
        return $this->textIn('description', $locale) ?: null;
    }
}
