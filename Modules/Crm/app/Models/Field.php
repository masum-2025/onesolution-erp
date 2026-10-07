<?php

namespace Modules\Crm\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An extra field a company adds to contacts, deals, quotes or quote lines. Key and type never change (values depend on them); a field is switched off, never removed.
 *
 * options (choice fields): [{value, label: {en, bn}}].
 */
#[Fillable(['organization_id', 'entity', 'key', 'type', 'options', 'is_required', 'on_print', 'sort_order', 'is_active', 'version'])]
class Field extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const ENTITIES = ['contact', 'deal', 'quote', 'quote_line'];

    public const TYPES = ['text', 'long_text', 'number', 'money', 'date', 'choice', 'yes_no'];

    protected $table = 'crm_fields';

    /** @var list<string> */
    public array $translatable = ['label'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'on_print' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
