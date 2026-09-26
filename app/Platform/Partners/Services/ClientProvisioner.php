<?php

namespace App\Platform\Partners\Services;

use App\Models\User;
use App\Platform\Invitations\Services\InvitationService;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A new client from the partner console or the partner API: the account
 * (PartnerClientService), with its owner found by email or, when they have
 * no account yet, invited by email to set a password.
 */
class ClientProvisioner
{
    public function __construct(private PartnerClientService $clients, private InvitationService $invitations) {}

    /**
     * @param  array<string, mixed>  $data  Validated StoreClientRequest data.
     * @return array{group: Organization, company: Organization, owner_invited: bool}
     */
    public function create(Partner $partner, array $data, User $actor): array
    {
        $email = mb_strtolower((string) $data['owner_email']);
        $name = $data['owner_name'] ?? null;

        if ($name === null && ! User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['owner_email' => __('tenancy.errors.user_not_found_invite')]);
        }

        return DB::transaction(function () use ($partner, $data, $email, $name, $actor) {
            ['user' => $owner, 'created' => $created] = $this->invitations->userFor($email, (string) $name);
            $made = $this->clients->create($partner, array_diff_key($data, ['owner_email' => true, 'owner_name' => true]), $owner, $actor);
            $this->invitations->notify($owner, $made['group'], $actor, $created);

            return [...$made, 'owner_invited' => $created];
        });
    }
}
