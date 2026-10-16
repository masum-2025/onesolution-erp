<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Crm\Models\Contact;

/**
 * CRM's public service for modules that deal with other businesses (a
 * factory's buyers): organization contacts carrying a tag (e.g. "buyer"),
 * found by name, read one by one, or added. Calls, meetings and deals
 * stay CRM's own; other modules keep only the contact's id.
 *
 *     $buyers = app(Organizations::class)->search($company, 'buyer', 'H&M');
 *     $buyer = app(Organizations::class)->add($company, $branchId, 'buyer', ['name' => 'H&M'], $user);
 */
class Organizations
{
    public function __construct(private Crm $crm, private Contacts $contacts, private ModuleResolver $modules) {}

    public function available(Organization $company): bool
    {
        return $this->modules->isEnabled('crm', $company);
    }

    /**
     * Organization contacts with the tag, by name (all of them without a search), A to Z.
     *
     * @return list<array{id: string, name: string, email: ?string, phone: ?string, address: ?string}>
     */
    public function search(Organization $company, string $tag, ?string $term = null, int $limit = 50): array
    {
        if (! $this->available($company)) {
            return [];
        }
        $needle = trim((string) $term);

        return $this->crm->query(Contact::class, $company)->where('kind', 'organization')->where('is_active', true)->whereNull('anonymized_at')
            ->whereJsonContains('tags', $tag)
            ->when($needle !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($needle, '%_\\').'%'))
            ->orderBy('name')->limit($limit)->get()->map(fn (Contact $contact) => self::shape($contact))->values()->all();
    }

    /**
     * Organization contacts by id (unknown or anonymized ones left out).
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, name: string, email: ?string, phone: ?string, address: ?string}>
     */
    public function many(Organization $company, array $ids): array
    {
        if ($ids === [] || ! $this->available($company)) {
            return [];
        }

        return $this->crm->query(Contact::class, $company)->whereKey($ids)->whereNull('anonymized_at')->get()
            ->mapWithKeys(fn (Contact $contact) => [$contact->getKey() => self::shape($contact)])->all();
    }

    /** Whether an id is an organization contact of the company with the tag. */
    public function has(Organization $company, string $id, string $tag): bool
    {
        return $this->available($company) && $this->crm->query(Contact::class, $company)->whereKey($id)->where('kind', 'organization')
            ->whereNull('anonymized_at')->whereJsonContains('tags', $tag)->exists();
    }

    /**
     * Add an organization contact with the tag (CRM's own checks apply:
     * phone format and uniqueness, the company's required fields are not asked).
     *
     * @param  array{name: string, email?: ?string, phone?: ?string, address?: ?string, op_id?: ?string}  $data
     * @return array{id: string, name: string, email: ?string, phone: ?string, address: ?string}
     */
    public function add(Organization $company, string $unitId, string $tag, array $data, User $actor): array
    {
        $contact = $this->contacts->create($company, $unitId, [
            'kind' => 'organization', 'name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null, 'tags' => [$tag], 'source' => 'factory', 'op_id' => $data['op_id'] ?? null,
        ], $actor, requireFields: false);

        return self::shape($contact);
    }

    /** @return array{id: string, name: string, email: ?string, phone: ?string, address: ?string} */
    private static function shape(Contact $contact): array
    {
        return ['id' => $contact->getKey(), 'name' => $contact->name, 'email' => $contact->email, 'phone' => $contact->phone, 'address' => $contact->address];
    }
}
