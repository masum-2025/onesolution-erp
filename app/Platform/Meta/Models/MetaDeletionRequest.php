<?php

namespace App\Platform\Meta\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A data deletion request Meta sent for a person who removed the app: the
 * confirmation code they can look up, and how many connected pages went.
 * The person is kept only as a hash.
 */
#[Table('meta_deletion_requests')]
class MetaDeletionRequest extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['pages_removed' => 'integer'];
    }
}
