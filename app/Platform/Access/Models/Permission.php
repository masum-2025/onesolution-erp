<?php

namespace App\Platform\Access\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Database mirror of the PermissionCatalog (synced by access:sync), so role
 * rows can reference permissions and removed ones are kept as deprecated.
 */
#[Table('permissions')]
#[Fillable(['key', 'module_key', 'deprecated_at'])]
class Permission extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['deprecated_at' => 'datetime'];
    }
}
