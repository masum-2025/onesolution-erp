<?php

namespace Modules\TeacherFeedback\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A question of a round, in the institution's languages, answered with one to five stars.
 */
#[Fillable(['organization_id', 'round_id', 'text', 'sort_order'])]
class Question extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public array $translatable = ['text'];

    protected $table = 'tfb_questions';

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
