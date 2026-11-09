<?php

namespace Modules\AiAssistant\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A company's AI use for one module on one day (counts only). */
#[Fillable(['organization_id', 'module', 'day', 'calls', 'failures', 'input_tokens', 'output_tokens'])]
class AiUsage extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'ai_usage';

    protected function casts(): array
    {
        return ['day' => 'date', 'calls' => 'integer', 'failures' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }
}
