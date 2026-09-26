<?php

namespace App\Platform\Legal\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A client accepted one version of a legal document: who, when, in which
 * language. Append-only.
 */
#[Table('document_acceptances')]
class DocumentAcceptance extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'accepted_at' => 'datetime',
        ];
    }
}
