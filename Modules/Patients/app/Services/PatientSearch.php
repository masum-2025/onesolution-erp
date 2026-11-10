<?php

namespace Modules\Patients\Services;

use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Modules\Patients\Models\Patient;

/**
 * Finding a patient the way reception does: a patient number (exact), a
 * mobile (any form people type, Bangla digits too), or a part of the name.
 * Merged patients are left out. Exact matches come first.
 */
class PatientSearch
{
    public function __construct(private PatientsStore $store) {}

    /**
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    public function apply(Organization $company, Builder $query, string $term): Builder
    {
        $query->whereNull('merged_into_id');
        $term = trim($term);
        if ($term === '') {
            return $query->orderByDesc('created_at')->orderByDesc('id');
        }
        $mobile = PhoneNumber::normalize($term, $this->store->country($company));
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function ($inner) use ($term, $mobile, $like) {
            $inner->where('mrn', strtoupper($term))
                ->orWhere('name', 'like', $like)
                ->orWhere('name_local', 'like', $like);
            if ($mobile !== null) {
                $inner->orWhere('mobile', $mobile);
            }
        })->orderBy('name')->orderBy('id');
    }
}
