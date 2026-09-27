<?php

namespace App\Platform\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone already had a free trial with this partner. One row per subject:
 * "user:{id}", and a hash of each verified email and phone, so a second
 * account with the same phone does not get a second trial. Nothing is mass
 * assignable; rows are never updated.
 *
 * Not tenant-scoped on purpose: it must be checked across accounts.
 */
#[Table('trial_grants')]
class TrialGrant extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];
}
