<?php

namespace Modules\Education\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A design for ID cards, certificates or letters: its page, the items on it
 * (texts with {placeholders}, images, the photo, the QR code, lines and
 * boxes, placed in millimetres) and what is asked when issuing. Written in
 * one language; documents keep the design they were issued with.
 */
#[Fillable(['organization_id', 'key', 'kind', 'name', 'locale', 'page', 'layout', 'inputs', 'status', 'version', 'created_by'])]
class DocumentTemplate extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const KINDS = ['id_card', 'certificate', 'letter'];

    public const STATUSES = ['draft', 'active', 'retired'];

    protected $table = 'edu_document_templates';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'page' => 'array',
            'layout' => 'array',
            'inputs' => 'array',
            'version' => 'integer',
        ];
    }
}
