<?php

namespace App\Platform\Access\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A sector role template (platform data, database/seeders/data/role-templates.php).
 * Organizations clone templates into their own roles and adjust them.
 * Names: lang/{locale}/access.php "templates.{key}".
 */
#[Table('role_templates')]
#[Fillable(['key', 'sector_key', 'permissions', 'sort_order', 'deprecated_at'])]
class RoleTemplate extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['permissions' => 'array', 'deprecated_at' => 'datetime'];
    }

    public function label(?string $locale = null): string
    {
        return __("access.templates.{$this->key}.name", [], $locale);
    }

    public function description(?string $locale = null): string
    {
        return __("access.templates.{$this->key}.description", [], $locale);
    }
}
