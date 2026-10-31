<?php

namespace Modules\Compliance\Portal;

use App\Platform\Portal\Contracts\PortalSubjectPage;
use App\Platform\Portal\Contracts\PortalSubjectProvider;
use App\Platform\Portal\PortalSubject;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Modules\Compliance\Services\Place;

/**
 * A factory of the client (an open company or branch at or below the portal's
 * organization) shown in its portal to someone watching it: a buyer, a brand,
 * an outside auditor ("viewer"). Linked, the person sees the factory's audit
 * register (the Compliance portal screen): services, dates, days left, state;
 * never notes, renewals, quotations or invoices. A company linked shows its
 * branches too.
 */
class FactorySubjects implements PortalSubjectPage, PortalSubjectProvider
{
    public const KEY = 'compliance.factory';

    public function __construct(private Place $place) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(?string $locale = null): string
    {
        return __('compliance::compliance.portal.subject', [], $locale);
    }

    public function relations(): array
    {
        return ['viewer'];
    }

    public function find(Organization $organization, string $id): ?PortalSubject
    {
        $factory = $this->place->factories($organization)->firstWhere('id', $id);

        return $factory === null ? null : $this->subject($factory);
    }

    public function search(Organization $organization, string $term, int $limit = 20): array
    {
        $term = mb_strtolower(trim($term));

        return $this->place->factories($organization)
            ->filter(fn (Organization $factory) => $term === '' || str_contains(mb_strtolower($factory->displayName()), $term))
            ->take($limit)
            ->map(fn (Organization $factory) => $this->subject($factory))
            ->values()->all();
    }

    public function details(PortalSubject $subject, ?string $locale = null): array
    {
        $factory = Organization::query()->findOrFail($subject->id);
        $label = fn (string $key) => __('compliance::compliance.portal.'.$key, [], $locale);

        return [
            'name' => ['label' => $label('name'), 'value' => $factory->displayName($locale)],
            'kind' => ['label' => $label('kind'), 'value' => $label($factory->type === OrganizationType::Company ? 'kind_company' : 'kind_branch')],
        ];
    }

    public function portalPath(PortalSubject $subject): ?string
    {
        return "/portal/compliance/{$subject->id}";
    }

    private function subject(Organization $factory): PortalSubject
    {
        return new PortalSubject(self::KEY, $factory->getKey(), $factory->getKey(), $factory->displayName());
    }
}
