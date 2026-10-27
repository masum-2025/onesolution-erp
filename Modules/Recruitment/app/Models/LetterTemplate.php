<?php

namespace Modules\Recruitment\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** The company's own offer letter: its text per language with {placeholders}. One per company. */
#[Fillable(['organization_id', 'version'])]
class LetterTemplate extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'rec_letter_templates';

    /** @var list<string> */
    public array $translatable = ['body'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
