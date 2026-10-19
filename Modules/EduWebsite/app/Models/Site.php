<?php

namespace Modules\EduWebsite\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's public website: template, colours, names, contact and the
 * published snapshot the public sees (drafts never show until published).
 */
#[Fillable(['organization_id', 'slug', 'template', 'theme', 'logo_media_id', 'contact', 'locales', 'default_locale', 'status', 'version'])]
class Site extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const OFFLINE = 'offline';

    protected $table = 'web_sites';

    /** @var list<string> */
    public array $translatable = ['title', 'tagline'];

    protected function casts(): array
    {
        return [
            'theme' => 'array', 'contact' => 'array', 'locales' => 'array', 'published' => 'array',
            'published_at' => 'immutable_datetime', 'cache_version' => 'integer', 'version' => 'integer',
        ];
    }
}
