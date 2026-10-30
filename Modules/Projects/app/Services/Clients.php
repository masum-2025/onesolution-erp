<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Modules\Crm\Services\ContactDirectory;
use Modules\Projects\Exceptions\ProjectsException;

/**
 * A project's client. With CRM on at the company, a client is any CRM
 * contact, a person or an organization (chosen from the company's
 * contacts, those already tagged "client" first, and tagged "client" when
 * chosen; or added from here with its kind, name, company, phone, email and
 * address through CRM's public service). Without CRM, only the name typed
 * in. The project keeps the contact's id and the name it had.
 */
class Clients
{
    public const TAG = 'client';

    public function __construct(private ContactDirectory $directory) {}

    public function usesCrm(Organization $company): bool
    {
        return $this->directory->available($company);
    }

    /**
     * @param  'person'|'organization'|null  $kind
     * @return list<array{id: string, kind: string, name: string, company_name: ?string, phone: ?string, email: ?string, tagged: bool}>
     */
    public function search(Organization $company, ?string $term, ?string $kind = null): array
    {
        return $this->directory->search($company, $term, $kind, self::TAG);
    }

    /**
     * A new client in CRM, from the form.
     *
     * @param  array{kind: 'person'|'organization', name: string, company_name?: ?string, phone?: ?string, email?: ?string, address?: ?array<string, ?string>}  $data
     * @return array{id: string, kind: string, name: string, company_name: ?string, phone: ?string, email: ?string, tagged: bool}
     */
    public function add(Organization $company, Organization $unit, array $data, User $actor): array
    {
        if (! $this->usesCrm($company)) {
            throw ProjectsException::crmOff();
        }

        return $this->directory->add($company, $unit->getKey(), $data, self::TAG, $actor);
    }

    /**
     * The client of a new or changed project: a known contact (tagged "client"
     * now), else a name (added to CRM as an organization client when CRM is on).
     *
     * @return array{client_contact_id: string|null, client_name: string}
     */
    public function resolve(Organization $company, Organization $unit, ?string $contactId, ?string $name, User $actor): array
    {
        $name = trim((string) $name);
        if ($contactId !== null) {
            $contact = $this->directory->many($company, [$contactId])[$contactId] ?? throw ProjectsException::clientUnknown();
            $this->directory->tag($company, $contactId, self::TAG, $actor);

            return ['client_contact_id' => $contactId, 'client_name' => $contact['name']];
        }
        if ($name === '') {
            throw ProjectsException::clientNeeded();
        }
        if (! $this->usesCrm($company)) {
            return ['client_contact_id' => null, 'client_name' => mb_substr($name, 0, 190)];
        }
        $added = $this->directory->add($company, $unit->getKey(), ['kind' => 'organization', 'name' => mb_substr($name, 0, 150)], self::TAG, $actor);

        return ['client_contact_id' => $added['id'], 'client_name' => $added['name']];
    }

    /** 'person' or 'organization', or null when the contact is not found (CRM off, removed). */
    public function kindOf(Organization $company, string $contactId): ?string
    {
        return $this->directory->many($company, [$contactId])[$contactId]['kind'] ?? null;
    }

    /**
     * Contact details for an invoice's customer (name, phone, email), or null.
     *
     * @return array{name: string, phone: ?string, email: ?string}|null
     */
    public function details(Organization $company, string $contactId): ?array
    {
        $contact = $this->directory->many($company, [$contactId])[$contactId] ?? null;

        return $contact === null ? null : [
            // A person of a company is billed by their own name (the company shown on the contact).
            'name' => $contact['name'],
            'phone' => $contact['phone'],
            'email' => $contact['email'],
        ];
    }
}
