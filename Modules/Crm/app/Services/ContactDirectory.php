<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Crm\Models\Contact;

/**
 * CRM's public service for modules that pick a client among the company's
 * contacts, people and organizations alike (Projects): found by name, phone
 * or company, those carrying a tag first; added with a kind and a tag by
 * CRM's own rules (phone format and uniqueness); read by id; tagged. Calls,
 * deals and consent stay CRM's own; other modules keep only the contact's id.
 *
 *     $found = app(ContactDirectory::class)->search($company, 'Rahim', kind: 'person', tag: 'client');
 *     $added = app(ContactDirectory::class)->add($company, $branchId, ['kind' => 'organization', 'name' => 'Rider Group'], 'client', $user);
 */
class ContactDirectory
{
    public const KINDS = ['person', 'organization'];

    public function __construct(private Crm $crm, private Contacts $contacts, private ModuleResolver $modules) {}

    public function available(Organization $company): bool
    {
        return $this->modules->isEnabled('crm', $company);
    }

    /**
     * Active contacts by name, company name, phone or email; those with the tag
     * first, then A to Z. Without a search: the tagged ones and the newest.
     *
     * @param  'person'|'organization'|null  $kind
     * @return list<array{id: string, kind: string, name: string, company_name: ?string, phone: ?string, email: ?string, tagged: bool}>
     */
    public function search(Organization $company, ?string $term = null, ?string $kind = null, ?string $tag = null, int $limit = 30): array
    {
        if (! $this->available($company)) {
            return [];
        }
        $needle = trim((string) $term);
        $like = '%'.addcslashes($needle, '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $needle);

        $found = $this->crm->query(Contact::class, $company)->where('is_active', true)->whereNull('anonymized_at')
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($needle !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', $like)->orWhere('company_name', 'like', $like)->orWhere('email', 'like', $like)
                ->when(strlen((string) $digits) >= 4, fn ($phone) => $phone->orWhere('phone', 'like', '%'.$digits.'%'))))
            // A few more than asked: the tagged ones are put first below.
            ->orderByDesc('updated_at')->limit($limit * 3)->get()
            ->map(fn (Contact $contact) => self::shape($contact, $tag));

        return $found->sortBy(fn (array $contact) => [$contact['tagged'] ? 0 : 1, mb_strtolower($contact['name'])])->take($limit)->values()->all();
    }

    /**
     * Contacts by id (unknown or anonymized ones left out).
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, kind: string, name: string, company_name: ?string, phone: ?string, email: ?string, tagged: bool}>
     */
    public function many(Organization $company, array $ids, ?string $tag = null): array
    {
        if ($ids === [] || ! $this->available($company)) {
            return [];
        }

        return $this->crm->query(Contact::class, $company)->whereKey($ids)->whereNull('anonymized_at')->get()
            ->mapWithKeys(fn (Contact $contact) => [$contact->getKey() => self::shape($contact, $tag)])->all();
    }

    /**
     * A new contact of a kind, carrying the tag (CRM's checks apply; the company's
     * required extra fields are not asked here).
     *
     * @param  array{kind: 'person'|'organization', name: string, company_name?: ?string, phone?: ?string, email?: ?string, address?: ?array<string, ?string>, op_id?: ?string}  $data
     * @return array{id: string, kind: string, name: string, company_name: ?string, phone: ?string, email: ?string, tagged: bool}
     */
    public function add(Organization $company, string $unitId, array $data, string $tag, User $actor, string $source = 'projects'): array
    {
        $contact = $this->contacts->create($company, $unitId, [
            'kind' => $data['kind'],
            'name' => $data['name'],
            'company_name' => $data['kind'] === 'person' ? ($data['company_name'] ?? null) : null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'tags' => [$tag],
            'source' => $source,
            'op_id' => $data['op_id'] ?? null,
        ], $actor, requireFields: false);

        return self::shape($contact, $tag);
    }

    /** Put a tag on a contact (nothing when it has it already). */
    public function tag(Organization $company, string $id, string $tag, User $actor): void
    {
        $contact = $this->crm->query(Contact::class, $company)->whereKey($id)->first();
        if ($contact === null || in_array($tag, (array) $contact->tags, true)) {
            return;
        }
        $this->contacts->update($company, $contact, $contact->version, ['tags' => [...(array) $contact->tags, $tag]], $actor);
    }

    /** @return array{id: string, kind: string, name: string, company_name: ?string, phone: ?string, email: ?string, tagged: bool} */
    private static function shape(Contact $contact, ?string $tag): array
    {
        return [
            'id' => $contact->getKey(),
            'kind' => $contact->kind,
            'name' => $contact->name,
            'company_name' => $contact->company_name,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'tagged' => $tag !== null && in_array($tag, (array) $contact->tags, true),
        ];
    }
}
