<?php

namespace App\Platform\Localization\Models;

use App\Platform\Localization\Enums\Channel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One text for one key at one level. Plain text with placeholders only:
 * never markup (shown escaped everywhere).
 */
#[Table('translation_overrides')]
class TranslationOverride extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['channel' => Channel::class, 'version' => 'integer'];
    }
}
