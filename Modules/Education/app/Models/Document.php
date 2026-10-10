<?php

namespace Modules\Education\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An issued ID card, certificate or letter: append-only. Its number, a
 * random code people verify it by (in the QR), and the design and values as
 * they were at issue, so it prints the same years later. A wrong one is
 * revoked with a reason, never deleted.
 */
#[Fillable([
    'organization_id', 'unit_id', 'template_id', 'template_version', 'kind', 'title', 'student_id', 'number', 'code', 'locale',
    'snapshot', 'has_sensitive', 'photo_path', 'issued_on', 'valid_until', 'issued_by', 'revoked_at', 'revoked_by', 'revoke_reason', 'op_id',
])]
class Document extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'edu_documents';

    /** @var list<string> */
    public array $translatable = ['title'];

    protected $hidden = ['photo_path'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'has_sensitive' => 'boolean',
            'template_version' => 'integer',
            'issued_on' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** valid, revoked or expired, on a day. */
    public function statusOn(\DateTimeInterface $day): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->valid_until !== null && $this->valid_until->format('Y-m-d') < $day->format('Y-m-d') ? 'expired' : 'valid';
    }
}
