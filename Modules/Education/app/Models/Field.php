<?php

namespace Modules\Education\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A field the institution adds to students, guardians or admissions. Key and type never change; a field is switched off, never removed.
 */
#[Fillable(['organization_id', 'entity', 'key', 'label', 'type', 'options', 'is_required', 'portal_visible', 'on_documents', 'is_sensitive', 'sort_order', 'is_active', 'version'])]
class Field extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const ENTITIES = ['student', 'guardian', 'admission'];

    public const TYPES = ['text', 'long_text', 'number', 'date', 'choice', 'multi_choice', 'yes_no'];

    protected $table = 'edu_fields';

    /** @var list<string> */
    public array $translatable = ['label'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'portal_visible' => 'boolean',
            'on_documents' => 'boolean',
            'is_sensitive' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
