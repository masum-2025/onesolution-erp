<?php

namespace Modules\Education\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An application and its decision; admitted, it becomes a student and an enrollment.
 */
#[Fillable(['organization_id', 'unit_id', 'number', 'program_id', 'level_id', 'session_id', 'applicant', 'source', 'source_ref', 'status', 'note', 'student_id', 'decided_at', 'decided_by', 'created_by', 'op_id', 'version'])]
class Admission extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const STATUSES = ['applied', 'test', 'offered', 'admitted', 'rejected', 'withdrawn'];

    /** Decisions that end an application. */
    public const FINAL = ['admitted', 'rejected', 'withdrawn'];

    protected $table = 'edu_admissions';

    /**
     * What an application is found by: the applicant's names in lower case
     * and the digits of their and their guardians' phones (Bangla digits
     * as ASCII), kept in search_text.
     *
     * @param  array<string, mixed>  $applicant
     */
    public static function searchText(array $applicant): string
    {
        $phones = [$applicant['phone'] ?? null, ...array_map(fn ($guardian) => is_array($guardian) ? ($guardian['phone'] ?? null) : null, (array) ($applicant['guardians'] ?? []))];
        $digits = array_filter(array_map(fn ($phone) => self::digits((string) $phone), $phones), fn (string $phone) => $phone !== '');
        $names = array_filter([$applicant['name'] ?? null, $applicant['name_local'] ?? null], fn ($name) => is_string($name) && trim($name) !== '');

        return mb_substr(trim(mb_strtolower(implode(' ', [...$names, ...$digits]))), 0, 500);
    }

    /** Digits of a phone or a search (Bangla digits as ASCII). */
    public static function digits(string $text): string
    {
        return (string) preg_replace('/\D/', '', strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
    }

    protected function casts(): array
    {
        return [
            'applicant' => 'array',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
