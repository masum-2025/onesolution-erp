<?php

namespace Modules\Accounting\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Enums\AccountStatus;
use Modules\Accounting\Enums\AccountType;

/**
 * One account of a company's chart (Cash in hand, Salaries and wages), named
 * in every language the client uses. Group accounts are headings; entries go
 * to the others. Never deleted: archived once no longer used.
 */
#[Fillable(['organization_id', 'parent_id', 'code', 'type', 'is_group', 'status', 'version'])]
class Account extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'acc_accounts';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'status' => AccountStatus::class,
            'is_group' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function isPostable(): bool
    {
        return ! $this->is_group && $this->status === AccountStatus::Active;
    }
}
