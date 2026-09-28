<?php

namespace App\Platform\Partners\Services;

use App\Models\User;
use App\Platform\Invitations\Services\InvitationService;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A new client, or a new company in a client's group, from the partner
 * console or the partner API (PartnerClientService), with its owner found by
 * email or, when they have no account yet, invited by email to set a
 * password. In an existing group the owner is optional: the group's owners
 * already manage the new company.
 */
class ClientProvisioner
{
    public function __construct(private PartnerClientService $clients, private InvitationService $invitations) {}

    /**
     * @param  array<string, mixed>  $data  Validated StoreClientRequest data.
     * @return array{client: Organization, company: Organization, branches: list<Organization>, owner_invited: bool}
     */
    public function create(Partner $partner, array $data, User $actor): array
    {
        $email = isset($data['owner_email']) ? mb_strtolower((string) $data['owner_email']) : null;
        $name = $data['owner_name'] ?? null;

        if ($email !== null && $name === null && ! User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['owner_email' => __('tenancy.errors.user_not_found_invite')]);
        }

        return DB::transaction(function () use ($partner, $data, $email, $name, $actor) {
            ['user' => $owner, 'created' => $created] = $email === null
                ? ['user' => null, 'created' => false]
                : $this->invitations->userFor($email, (string) $name);

            $made = $this->clients->create($partner, array_diff_key($data, ['owner_email' => true, 'owner_name' => true]), $owner, $actor);

            if ($owner !== null) {
                // Invited where they were made owner: the top organization, or the new company in a group.
                $this->invitations->notify($owner, ($data['structure'] ?? null) === PartnerClientService::EXISTING_GROUP ? $made['company'] : $made['client'], $actor, $created);
            }

            return [...$made, 'owner_invited' => $created];
        });
    }
}
