<?php

namespace Modules\EduWebsite\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A draft page of the website: its address, title, place in the menu, and blocks. */
#[Fillable(['organization_id', 'site_id', 'slug', 'sort_order', 'in_menu', 'is_home', 'version'])]
class Page extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'web_pages';

    /** @var list<string> */
    public array $translatable = ['title'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'in_menu' => 'boolean', 'is_home' => 'boolean', 'version' => 'integer'];
    }
}
