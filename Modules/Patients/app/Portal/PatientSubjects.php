<?php

namespace Modules\Patients\Portal;

use App\Platform\Portal\Contracts\PortalSubjectPage;
use App\Platform\Portal\Contracts\PortalSubjectProvider;
use App\Platform\Portal\PortalSubject;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Modules\Patients\Models\Patient;

/**
 * A patient (or a parent for a child, "guardian") sees their own record in
 * the client's portal: name, patient number, age, sex, blood group, and
 * their history on the portal page. Never the ID number, allergies, address
 * or who looked. Lookups stay inside the given organization and its units,
 * in the client's own database, without a tenant context (joining); merged
 * patients are not offered.
 */
class PatientSubjects implements PortalSubjectPage, PortalSubjectProvider
{
    public const KEY = 'patients.patient';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(?string $locale = null): string
    {
        return __('patients::messages.portal.subject', [], $locale);
    }

    public function relations(): array
    {
        return ['self', 'guardian'];
    }

    public function find(Organization $organization, string $id): ?PortalSubject
    {
        $patient = $this->query($organization)->whereKey($id)->first();

        return $patient === null ? null : $this->subject($patient);
    }

    public function search(Organization $organization, string $term, int $limit = 20): array
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $this->query($organization)
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('mrn', strtoupper($term))->orWhere('mobile', 'like', $like)))
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (Patient $patient) => $this->subject($patient))
            ->all();
    }

    public function details(PortalSubject $subject, ?string $locale = null): array
    {
        /** @var Patient $patient */
        $patient = Patient::inTenantOf($subject->organizationId)->withoutGlobalScope(OrganizationScope::class)->findOrFail($subject->id);
        $label = fn (string $key) => __('patients::messages.portal.'.$key, [], $locale);

        return [
            'name' => ['label' => $label('name'), 'value' => $patient->name],
            'mrn' => ['label' => $label('mrn'), 'value' => $patient->mrn],
            'age' => ['label' => $label('age'), 'value' => $patient->ageOn(CarbonImmutable::now())],
            'sex' => ['label' => $label('sex'), 'value' => __('patients::messages.sexes.'.$patient->sex, [], $locale)],
            'blood_group' => ['label' => $label('blood_group'), 'value' => $patient->blood_group],
        ];
    }

    public function portalPath(PortalSubject $subject): ?string
    {
        return '/portal/patients';
    }

    /** Patients of the organization (the company keeps them all) that are not merged, in the client's own database. */
    private function query(Organization $organization)
    {
        $ids = Organization::query()->subtreeOf($organization)->pluck('id')->all();

        return Patient::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)
            ->whereIn('organization_id', $ids)
            ->whereNull('merged_into_id');
    }

    private function subject(Patient $patient): PortalSubject
    {
        return new PortalSubject(self::KEY, $patient->getKey(), $patient->organization_id, $patient->name);
    }
}
