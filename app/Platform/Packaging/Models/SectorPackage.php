<?php

namespace App\Platform\Packaging\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Database mirror of the SectorCatalog (packaging:sync). Platform data.
 */
#[Table('sector_packages')]
#[Fillable(['key', 'modules', 'rules', 'role_templates', 'demo_seeder', 'version', 'sort_order', 'deprecated_at'])]
class SectorPackage extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['modules' => 'array', 'rules' => 'array', 'role_templates' => 'array', 'deprecated_at' => 'datetime'];
    }
}
