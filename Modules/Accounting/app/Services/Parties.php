<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\Party;

/**
 * Customers and vendors of a company. A party is a customer, a vendor or
 * both; codes are unique in the company. Never deleted: made inactive.
 */
class Parties
{
    /** Details people may set directly. */
    public const FIELDS = ['name', 'is_customer', 'is_vendor', 'code', 'phone', 'email', 'address', 'tax_number', 'payment_terms_days', 'is_active'];

    public function __construct(private Books $books, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated by PartyRequest.
     */
    public function create(Organization $company, array $data, User $actor): Party
    {
        return $this->books->transaction($company, function () use ($company, $data, $actor) {
            $party = new Party;
            $party->fill([...array_intersect_key($data, array_flip(self::FIELDS)), 'organization_id' => $company->getKey(), 'version' => 1]);
            $party->is_active ??= true;
            $this->assertValid($company, $party);
            $party->save();

            $this->audit->record('accounting.party_created', $party, new: $this->auditValues($party), actor: $actor, organizationId: $company->getKey());

            return $party;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields to change.
     */
    public function update(Organization $company, Party $party, int $baseVersion, array $data, User $actor): Party
    {
        return $this->books->transaction($company, function () use ($company, $party, $baseVersion, $data, $actor) {
            /** @var Party $party */
            $party = $this->books->query(Party::class, $company)->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
            if ($party->version !== $baseVersion) {
                throw AccountingException::versionConflict(['version' => $party->version]);
            }
            $old = $this->auditValues($party);

            $party->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if (! $party->isDirty()) {
                return $party;
            }
            $this->assertValid($company, $party);
            $party->version = $party->version + 1;
            $party->save();

            // Field names only for personal details (phone, email, address); values for the rest.
            $this->audit->record('accounting.party_updated', $party, old: $old, new: $this->auditValues($party), actor: $actor, organizationId: $company->getKey());

            return $party;
        });
    }

    private function assertValid(Organization $company, Party $party): void
    {
        if (! $party->is_customer && ! $party->is_vendor) {
            throw ValidationException::withMessages(['is_customer' => __('accounting::accounting.validation.party_role')]);
        }
        if ($party->code !== null && $this->books->query(Party::class, $company)->where('code', $party->code)->whereKeyNot($party->getKey() ?? '')->exists()) {
            throw ValidationException::withMessages(['code' => __('accounting::accounting.validation.code_taken')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(Party $party): array
    {
        return [
            'name' => $party->name, 'code' => $party->code, 'is_customer' => $party->is_customer, 'is_vendor' => $party->is_vendor,
            'payment_terms_days' => $party->payment_terms_days, 'is_active' => $party->is_active,
        ];
    }
}
