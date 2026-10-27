<?php

namespace Modules\Recruitment\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A person who applied to the company (or HR added): name and contacts encrypted, found again by the keyed hash of the email. */
#[Fillable(['organization_id', 'name', 'email', 'email_hash', 'phone', 'locale', 'consent_at'])]
class Candidate extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'rec_candidates';

    /** Never in arrays or JSON by accident (the presenter picks what to show). */
    protected $hidden = ['name', 'email', 'email_hash', 'phone'];

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'email' => 'encrypted', 'phone' => 'encrypted', 'consent_at' => 'datetime'];
    }

    /** The keyed hash an email is found by (lower-cased, trimmed). */
    public static function hashOf(string $email): string
    {
        return hash_hmac('sha256', 'recruitment.email|'.mb_strtolower(trim($email)), (string) config('app.key'));
    }
}
