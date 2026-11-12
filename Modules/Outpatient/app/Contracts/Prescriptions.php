<?php

namespace Modules\Outpatient\Contracts;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;

/**
 * How other modules (the pharmacy counter) read a doctor's finished
 * prescription, never through the outpatient tables. Only what selling the
 * medicines needs: the medicines, the doctor's name and registration number,
 * the patient's name and the date. Never the complaints, findings, diagnosis
 * or advice. Drafts are never given; a replaced revision answers as the one
 * in force. Seen from a unit: other branches only when
 * patients.share_between_branches is on there.
 */
interface Prescriptions
{
    /**
     * Prescriptions finished in the last $days days, newest first: by the
     * printed code (the consultation id) or the patient's number, mobile or name.
     *
     * @return list<array{id: string, date: string, patient_name: string, patient_mrn: string, doctor_name: string}>
     */
    public function search(Organization $company, Organization $unit, string $term, int $days, int $limit = 20): array;

    /**
     * One prescription with its medicines, or null when it is not there, not
     * finished, too old or out of reach. A viewer is written in the patient's
     * "who looked" log.
     *
     * @return array{id: string, date: string, amended: bool, patient_id: string, patient_name: string, patient_mrn: string,
     *     doctor_name: string, doctor_registration_no: ?string,
     *     medicines: list<array{medicine_id: ?string, medicine_text: string, dose: ?string, duration: ?string, timing: ?string}>}|null
     */
    public function find(Organization $company, Organization $unit, string $id, int $days, ?User $viewer = null): ?array;
}
