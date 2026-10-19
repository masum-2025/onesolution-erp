<?php

namespace Modules\EduWebsite\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A notice on the website's notice board: plain text per language, an optional PDF, pinned or not. */
#[Fillable(['organization_id', 'site_id', 'media_id', 'published_on', 'pinned', 'is_published', 'version'])]
class Notice extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'web_notices';

    /** @var list<string> */
    public array $translatable = ['title', 'body'];

    protected function casts(): array
    {
        return ['published_on' => 'immutable_date', 'pinned' => 'boolean', 'is_published' => 'boolean', 'version' => 'integer'];
    }
}
