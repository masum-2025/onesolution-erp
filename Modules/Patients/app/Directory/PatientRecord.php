<?php

namespace Modules\Patients\Directory;

/**
 * A patient as other modules see it: enough to show and pick, never the ID
 * number, allergies or address.
 */
final readonly class PatientRecord
{
    public function __construct(
        public string $id,
        public string $mrn,
        public string $name,
        public string $sex,
        public ?int $age,
        public ?string $mobile,
        public string $unitId,
    ) {}

    /** "P000123 · Rahim Uddin" */
    public function label(): string
    {
        return $this->mrn.' · '.$this->name;
    }
}
