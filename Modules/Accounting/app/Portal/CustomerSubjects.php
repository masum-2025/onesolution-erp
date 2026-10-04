<?php

namespace Modules\Accounting\Portal;

use App\Platform\Portal\Contracts\PortalSubjectPage;
use App\Platform\Portal\Contracts\PortalSubjectProvider;
use App\Platform\Portal\PortalSubject;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Services\Books;

/**
 * A customer of the company in its portal ("self"): a guardian paying fees,
 * a shop customer, a client firm. Linked, the person sees their own invoices
 * (the Accounting portal screen) and what they owe; never other customers,
 * accounts or journals. Lookups name the company's books explicitly (no
 * tenant context while joining).
 */
class CustomerSubjects implements PortalSubjectPage, PortalSubjectProvider
{
    public const KEY = 'accounting.customer';

    public function __construct(private Books $books) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(?string $locale = null): string
    {
        return __('accounting::accounting.portal.subject', [], $locale);
    }

    public function relations(): array
    {
        return ['self'];
    }

    public function find(Organization $organization, string $id): ?PortalSubject
    {
        $party = $this->customers($organization)?->whereKey($id)->first();

        return $party === null ? null : $this->subject($party);
    }

    public function search(Organization $organization, string $term, int $limit = 20): array
    {
        $query = $this->customers($organization);
        if ($query === null) {
            return [];
        }

        return $query
            ->when($term !== '', fn ($inner) => $inner->where(fn ($match) => $match
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('code', 'like', '%'.$term.'%')
                ->orWhere('phone', 'like', '%'.$term.'%')))
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (Party $party) => $this->subject($party))
            ->all();
    }

    public function details(PortalSubject $subject, ?string $locale = null): array
    {
        $company = Organization::query()->findOrFail($subject->organizationId);
        /** @var Party $party */
        $party = $this->books->query(Party::class, $company)->findOrFail($subject->id);
        $label = fn (string $key) => __('accounting::accounting.portal.'.$key, [], $locale);

        return [
            'name' => ['label' => $label('name'), 'value' => $party->name],
            'code' => ['label' => $label('code'), 'value' => $party->code],
            // What they owe is on the invoices screen, written as money in their language.
            'phone' => ['label' => $label('phone'), 'value' => $party->phone],
        ];
    }

    public function portalPath(PortalSubject $subject): ?string
    {
        return "/portal/invoices?customer={$subject->id}";
    }

    /**
     * Active customers in the books of the organization, when it keeps books.
     *
     * @return Builder<Party>|null
     */
    private function customers(Organization $organization): ?Builder
    {
        if (! in_array($organization->type, [OrganizationType::Company, OrganizationType::Personal], true) || ! $this->books->isSetUp($organization)) {
            return null;
        }

        return $this->books->query(Party::class, $organization)->where('is_customer', true)->where('is_active', true);
    }

    private function subject(Party $party): PortalSubject
    {
        return new PortalSubject(self::KEY, $party->getKey(), $party->organization_id, $party->name);
    }
}
