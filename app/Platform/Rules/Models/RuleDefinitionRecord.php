<?php

namespace App\Platform\Rules\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Database mirror of a RuleDefinition, kept for reporting and to remember
 * rules that were removed from code (deprecated_at). The in-code RuleCatalog
 * stays the source of truth for resolution.
 */
#[Table('rule_definitions')]
#[Fillable([
    'key', 'module_key', 'type', 'schema', 'default_value', 'nullable', 'label', 'description',
    'overridable_levels', 'edit_permission', 'requires_approval', 'sensitive',
    'country_specific', 'category', 'sort_order', 'deprecated_at',
])]
class RuleDefinitionRecord extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'schema' => 'json',
            'default_value' => 'json',
            'overridable_levels' => 'array',
            'nullable' => 'boolean',
            'requires_approval' => 'boolean',
            'sensitive' => 'boolean',
            'country_specific' => 'boolean',
            'deprecated_at' => 'datetime',
        ];
    }
}
