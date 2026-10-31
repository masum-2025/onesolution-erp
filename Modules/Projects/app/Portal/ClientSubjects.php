<?php

namespace Modules\Projects\Portal;

use App\Platform\Portal\Contracts\PortalSubjectPage;
use App\Platform\Portal\Contracts\PortalSubjectProvider;
use App\Platform\Portal\PortalSubject;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Modules\Projects\Services\Clients;
use Modules\Projects\Services\Workplace;

/**
 * A client of the company (a CRM contact tagged "client") following their
 * projects in its portal: the client themselves or someone of theirs
 * ("client"). Linked, the person sees those projects (the Projects portal
 * screen): status, dates, how many tasks are done and (by rule) the task
 * titles; never people, notes, money or other clients. Without CRM a client
 * is only a name, so there is nothing to link.
 */
class ClientSubjects implements PortalSubjectPage, PortalSubjectProvider
{
    public const KEY = 'projects.client';

    public function __construct(private Clients $clients, private Workplace $place) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(?string $locale = null): string
    {
        return __('projects::projects.portal.subject', [], $locale);
    }

    public function relations(): array
    {
        return ['client'];
    }

    public function find(Organization $organization, string $id): ?PortalSubject
    {
        $company = $this->companyOf($organization);
        $contact = $company === null ? null : ($this->clients->many($company, [$id])[$id] ?? null);

        return $contact === null || ! $contact['tagged'] ? null : $this->subject($company, $contact);
    }

    public function search(Organization $organization, string $term, int $limit = 20): array
    {
        $company = $this->companyOf($organization);
        if ($company === null) {
            return [];
        }

        return collect($this->clients->search($company, $term))
            ->where('tagged', true)->take($limit)
            ->map(fn (array $contact) => $this->subject($company, $contact))
            ->values()->all();
    }

    public function details(PortalSubject $subject, ?string $locale = null): array
    {
        $company = Organization::query()->findOrFail($subject->organizationId);
        $contact = $this->clients->many($company, [$subject->id])[$subject->id] ?? null;
        $label = fn (string $key) => __('projects::projects.portal.'.$key, [], $locale);

        return [
            'name' => ['label' => $label('name'), 'value' => $contact['name'] ?? $subject->name],
            'company_name' => ['label' => $label('company_name'), 'value' => $contact['company_name'] ?? null],
        ];
    }

    public function portalPath(PortalSubject $subject): ?string
    {
        return "/portal/projects/{$subject->id}";
    }

    /** The company whose CRM holds the clients: the portal's own, or the one above its branch. */
    private function companyOf(Organization $organization): ?Organization
    {
        if (! in_array($organization->type, [OrganizationType::Company, OrganizationType::Branch], true)) {
            return null;
        }
        $company = $this->place->companyOf($organization);

        return $this->clients->usesCrm($company) ? $company : null;
    }

    /**
     * @param  array{id: string, name: string}  $contact
     */
    private function subject(Organization $company, array $contact): PortalSubject
    {
        return new PortalSubject(self::KEY, $contact['id'], $company->getKey(), $contact['name']);
    }
}
