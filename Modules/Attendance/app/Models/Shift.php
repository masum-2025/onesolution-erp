<?php

namespace Modules\Attendance\Models;

use App\Platform\Support\HasTranslatedTexts;
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Working hours of a company: start and end in minutes after midnight (an
 * end at or before the start is the next day: a night shift) and the unpaid
 * break. Switched off, never deleted, once used.
 */
#[Fillable(['organization_id', 'code', 'start_minute', 'end_minute', 'break_minutes', 'is_active', 'version'])]
class Shift extends Model
{
    use BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase;

    protected $table = 'att_shifts';

    /** @var list<string> */
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['start_minute' => 'integer', 'end_minute' => 'integer', 'break_minutes' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    public function overnight(): bool
    {
        return $this->end_minute <= $this->start_minute;
    }

    /** Minutes from start to end (across midnight for a night shift). */
    public function lengthMinutes(): int
    {
        return ($this->overnight() ? 1440 : 0) + $this->end_minute - $this->start_minute;
    }

    /** Minutes the shift expects to be worked: its length less the break. */
    public function expectedMinutes(): int
    {
        return max(0, $this->lengthMinutes() - $this->break_minutes);
    }
}
