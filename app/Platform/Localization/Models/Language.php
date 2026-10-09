<?php

namespace App\Platform\Localization\Models;

use App\Platform\Localization\Enums\LanguageStatus;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A language added by the platform at runtime. Its texts are platform-level
 * translation overrides; where one is missing, its fallback file language shows.
 */
#[Table('languages')]
class Language extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['status' => LanguageStatus::class, 'published_at' => 'datetime'];
    }
}
