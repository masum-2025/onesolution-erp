<?php

namespace Modules\Patients\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Patients\Events\PatientRegistered;
use Modules\Patients\Exceptions\PatientsException;
use Modules\Patients\Models\Patient;

/**
 * Registering and changing patients. A new patient gets the company's next
 * patient number; the mobile is kept in one form (E.164, country from the
 * company); an age without a birth date becomes an estimated birth date, so
 * the age grows by itself. Before a new record is made, patients with the same
 * ID number, or the same mobile (and name, rule patients.duplicate_check), are
 * shown unless the person confirms. One op id makes one record; changes need
 * the version. Patients are never deleted (a duplicate is merged instead).
 * The audit log keeps the patient number and the names of changed fields,
 * never their values.
 */
class Patients
{
    private const FIELDS = ['name', 'name_local', 'sex', 'mobile', 'id_type', 'id_number', 'guardian_name', 'guardian_relation', 'blood_group', 'allergies', 'address'];

    public function __construct(
        private PatientsStore $store,
        private PatientNumbers $numbers,
        private PatientTimeline $timeline,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by PatientRequest.
     */
    public function register(Organization $company, Organization $unit, array $data, User $actor): Patient
    {
        if (($data['op_id'] ?? null) !== null) {
            $done = $this->store->query(Patient::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        $values = $this->values($company, $data);
        if (empty($values['date_of_birth'])) {
            throw PatientsException::ageOrBirthDate();
        }
        if ($this->store->rule('require_id_number', $unit) && ($values['id_number'] ?? null) === null) {
            throw PatientsException::idNumberRequired();
        }
        if (! ($data['confirm_new'] ?? false) && ($matches = $this->lookalikes($company, $unit, $values)) !== []) {
            throw PatientsException::possibleDuplicate($matches);
        }

        $patient = $this->store->transaction($company, function () use ($company, $unit, $values, $data, $actor) {
            $patient = new Patient([
                ...$values,
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'mrn' => $this->numbers->next($company),
                'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
            ]);
            $patient->save();
            $this->timeline->record($company, $patient->getKey(), 'patients', 'registration', $patient->getKey(), [
                'en' => __('patients::messages.timeline.registered', [], 'en'),
                'bn' => __('patients::messages.timeline.registered', [], 'bn'),
            ], CarbonImmutable::now());
            $this->audit->record('patients.patient_registered', $patient, new: ['mrn' => $patient->mrn, 'unit_id' => $unit->getKey()], actor: $actor, organizationId: $company->getKey());

            return $patient;
        });
        PatientRegistered::dispatch($company->getKey(), $patient->getKey(), $unit->getKey());

        return $patient;
    }

    /**
     * @param  array<string, mixed>  $data  Validated by PatientRequest (with base_version).
     */
    public function update(Organization $company, Patient $patient, array $data, User $actor): Patient
    {
        return $this->store->transaction($company, function () use ($company, $patient, $data, $actor) {
            $patient = $this->store->query(Patient::class, $company)->whereKey($patient->getKey())->lockForUpdate()->firstOrFail();
            if ($patient->isMerged()) {
                throw PatientsException::merged($patient->merged_into_id);
            }
            if ($patient->version !== (int) $data['base_version']) {
                throw PatientsException::versionConflict(['version' => $patient->version]);
            }
            $values = $this->values($company, $data);
            if (array_key_exists('date_of_birth', $values) && $values['date_of_birth'] === null) {
                throw PatientsException::ageOrBirthDate();
            }
            $patient->fill($values);
            $changed = array_keys(array_diff_key($patient->getDirty(), array_flip(['id_number_hash'])));
            if ($changed !== []) {
                $patient->version++;
                $patient->save();
                $this->audit->record('patients.patient_updated', $patient, new: ['mrn' => $patient->mrn, 'fields' => $changed], actor: $actor, organizationId: $company->getKey());
            }

            return $patient;
        });
    }

    /**
     * Columns from what was sent: the mobile normalised, an age as an estimated birth date, the ID number hashed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(Organization $company, array $data): array
    {
        $values = array_intersect_key($data, array_flip(self::FIELDS));
        foreach (['name_local', 'id_number', 'guardian_name', 'guardian_relation', 'allergies', 'address'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = ($trimmed = trim((string) $values[$field])) === '' ? null : $trimmed;
            }
        }
        if (isset($values['name'])) {
            $values['name'] = trim((string) $values['name']);
        }
        if (array_key_exists('mobile', $values)) {
            $values['mobile'] = $this->mobile($company, $values['mobile']);
        }
        if (array_key_exists('id_number', $values)) {
            $values['id_number_hash'] = Patient::hashOf($values['id_number']);
            if ($values['id_number'] === null) {
                $values['id_type'] = 'none';
            }
        }
        if (array_key_exists('date_of_birth', $data) && $data['date_of_birth'] !== null) {
            $values['date_of_birth'] = $data['date_of_birth'];
            $values['dob_estimated'] = false;
        } elseif (array_key_exists('age_years', $data) && $data['age_years'] !== null) {
            $values['date_of_birth'] = $this->store->today($company)->subYears((int) $data['age_years'])->toDateString();
            $values['dob_estimated'] = true;
        } elseif (array_key_exists('date_of_birth', $data) || array_key_exists('age_years', $data)) {
            $values['date_of_birth'] = null;
        }

        return $values;
    }

    /** A mobile in E.164 for the company's country, or null when empty. */
    public function mobile(Organization $company, mixed $input): ?string
    {
        if ($input === null || trim((string) $input) === '') {
            return null;
        }

        return PhoneNumber::normalize((string) $input, $this->store->country($company)) ?? throw PatientsException::badMobile();
    }

    /**
     * Patients of the company that look like this one (not merged ones), at most five, as the search shows them.
     *
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function lookalikes(Organization $company, Organization $unit, array $values): array
    {
        $mode = (string) $this->store->rule('duplicate_check', $unit);
        $hash = $values['id_number_hash'] ?? null;
        $mobile = $values['mobile'] ?? null;
        if ($hash === null && ($mode === 'off' || $mobile === null)) {
            return [];
        }
        $name = mb_strtolower((string) ($values['name'] ?? ''));

        return $this->store->query(Patient::class, $company)->whereNull('merged_into_id')
            ->where(function ($query) use ($hash, $mobile, $mode) {
                if ($hash !== null) {
                    $query->orWhere('id_number_hash', $hash);
                }
                if ($mode !== 'off' && $mobile !== null) {
                    $query->orWhere('mobile', $mobile);
                }
            })
            ->limit(20)->get()
            ->filter(fn (Patient $patient) => ($hash !== null && $patient->id_number_hash === $hash)
                || $mode === 'mobile'
                || $this->similarNames($name, mb_strtolower($patient->name)))
            ->take(5)
            ->map(fn (Patient $patient) => [
                'id' => $patient->getKey(), 'mrn' => $patient->mrn, 'name' => $patient->name,
                'age' => $patient->ageOn($this->store->today($company)), 'sex' => $patient->sex,
                'same_id_number' => $hash !== null && $patient->id_number_hash === $hash,
            ])->values()->all();
    }

    /** The same first word, or one name inside the other ("Rahim" and "Md Rahim Uddin"). */
    private function similarNames(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return true;
        }
        $first = fn (string $name) => preg_split('/\s+/u', trim($name))[0] ?? '';

        return str_contains($a, $b) || str_contains($b, $a) || $first($a) === $first($b);
    }
}
