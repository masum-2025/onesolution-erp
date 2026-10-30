<?php

namespace Modules\Projects\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Modules\Crm\Services\Organizations;
use Modules\Projects\Exceptions\ProjectsException;

/**
 * A project's client. With CRM on at the company, a client is a CRM
 * organization contact tagged "client" (found by name, or added from here
 * through CRM's public service); without CRM, only the name typed in. The
 * project keeps the contact's id and the name it had.
 */
class Clients
{
    public const TAG = 'client';

    public function __construct(private Organizations $organizations) {}

    public function usesCrm(Organization $company): bool
    {
        return $this->organizations->available($company);
    }

    /**
     * @return list<array{id: string, name: string, email: ?string, phone: ?string, address: ?string}>
     */
    public function search(Organization $company, ?string $term): array
    {
        return $this->organizations->search($company, self::TAG, $term, 30);
    }

    /**
     * The client of a new or changed project: a known contact, else a name
     * (added to CRM as a client when CRM is on).
     *
     * @return array{client_contact_id: string|null, client_name: string}
     */
    public function resolve(Organization $company, Organization $unit, ?string $contactId, ?string $name, User $actor): array
    {
        $name = trim((string) $name);
        if ($contactId !== null) {
            if (! $this->organizations->has($company, $contactId, self::TAG)) {
                throw ProjectsException::clientUnknown();
            }
            $contact = $this->organizations->many($company, [$contactId])[$contactId];

            return ['client_contact_id' => $contactId, 'client_name' => $contact['name']];
        }
        if ($name === '') {
            throw ProjectsException::clientNeeded();
        }
        if (! $this->usesCrm($company)) {
            return ['client_contact_id' => null, 'client_name' => mb_substr($name, 0, 190)];
        }
        $added = $this->organizations->add($company, $unit->getKey(), self::TAG, ['name' => mb_substr($name, 0, 190)], $actor);

        return ['client_contact_id' => $added['id'], 'client_name' => $added['name']];
    }
}
