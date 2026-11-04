<?php

namespace Modules\Letters\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's letter design: kind, code, page, letterhead, what is asked
 * when issuing, and the body per language as blocks. Draft -> active ->
 * retired; letters already issued keep their own copy.
 */
#[Fillable(['organization_id', 'preset', 'kind', 'code', 'page', 'letterhead', 'inputs', 'bodies', 'uses_salary', 'status', 'created_by', 'version'])]
class LetterTemplate extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $table = 'let_templates';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'page' => 'array',
            'letterhead' => 'array',
            'inputs' => 'array',
            'bodies' => 'array',
            'uses_salary' => 'boolean',
            'version' => 'integer',
        ];
    }
}
