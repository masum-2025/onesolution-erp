<?php

namespace App\Platform\Legal\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;

/**
 * One published version of a legal document: the platform's (partner_id
 * null) or a partner's. Never edited after publishing; a change is a new
 * version. Title and body are plain text per language.
 */
#[Table('legal_documents')]
class LegalDocument extends Model
{
    use HasUlids;

    public const KINDS = ['terms', 'privacy', 'dpa'];

    /** Clients accept these; the privacy notice is information. */
    public const ACCEPTED_KINDS = ['terms', 'dpa'];

    /**
     * What a client accepts: a personal workspace (one person, own data) only
     * the terms; the DPA is for businesses that process other people's data.
     *
     * @return list<string>
     */
    public static function acceptedKindsFor(Organization $root): array
    {
        return $root->type === OrganizationType::Personal ? ['terms'] : self::ACCEPTED_KINDS;
    }

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'title' => 'array',
            'body' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function text(string $field, ?string $locale = null): string
    {
        $values = (array) $this->{$field};
        $locale ??= app()->getLocale();

        return (string) ($values[$locale] ?? $values['en'] ?? reset($values));
    }
}
