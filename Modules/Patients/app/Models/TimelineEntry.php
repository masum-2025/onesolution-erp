<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a patient's history (a visit, report, prescription, bill),
 * written by the module that owns it, once per source record. The title is
 * translatable ({"bn": …, "en": …}) and carries no amounts or diagnoses.
 */
#[Fillable(['organization_id', 'patient_id', 'source_module', 'source_type', 'source_id', 'title', 'happened_at'])]
class TimelineEntry extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'patients_timeline';

    protected function casts(): array
    {
        return ['title' => 'array', 'happened_at' => 'immutable_datetime'];
    }

    public function titleIn(?string $locale): string
    {
        $title = (array) $this->title;

        return (string) ($title[$locale ?? app()->getLocale()] ?? $title['en'] ?? reset($title) ?: '');
    }
}
