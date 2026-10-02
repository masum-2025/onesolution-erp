<?php

namespace Modules\Hrm\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Hrm\Enums\CustomFieldType;

/**
 * An extra employee detail an organization asks for (blood group, MPO
 * number, shift). Defined at a company, branch or department and filled in
 * for employees of that unit and the units below. The key and type never
 * change (values depend on them); a field is switched off, never removed.
 *
 * options (choice fields): [{value, label: {en, bn}}].
 */
#[Fillable(['organization_id', 'company_id', 'key', 'type', 'options', 'is_required', 'sort_order', 'is_active', 'version'])]
class CustomField extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'hrm_custom_fields';

    /** @var list<string> */
    public array $translatable = ['label'];

    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return list<string> */
    public function optionValues(): array
    {
        return array_values(array_map(fn (array $option) => (string) $option['value'], $this->options ?? []));
    }
}
