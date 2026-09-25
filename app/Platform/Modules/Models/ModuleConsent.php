<?php

namespace App\Platform\Modules\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin consent for a module that processes data in a sensitive way (AI).
 * Consent at an organization also covers its descendants.
 */
#[Fillable(['organization_id', 'module_key', 'terms_version', 'granted_by', 'granted_at', 'reason'])]
class ModuleConsent extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ModuleConsent>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
