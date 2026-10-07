<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Points;

/**
 * CRM's public service for modules that sell over the counter (POS): find
 * a customer by mobile number, remember a new one, and record what they
 * bought (spent, purchases, loyalty points by rule crm.loyalty_points_per_100:
 * points for every 100 of the currency; 0 = no points).
 *
 *     $customer = app(Customers::class)->lookup($company, '01711-000000');   // null when unknown
 *     $customer = app(Customers::class)->remember($company, $branchId, 'Rahim', '01711000000', $cashier);
 */
class Customers
{
    public function __construct(
        private Crm $crm,
        private Contacts $contacts,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function available(Organization $company): bool
    {
        return $this->modules->isEnabled('crm', $company);
    }

    /**
     * @return array{id: string, name: string, phone: string, points: int, purchases: int, last_purchase_on: string|null}|null
     */
    public function lookup(Organization $company, string $phone): ?array
    {
        if (! $this->available($company)) {
            return null;
        }
        $contact = $this->contacts->findByPhone($company, $phone);

        return $contact === null ? null : self::shape($contact);
    }

    /**
     * The contact with this number, else a new one (source "pos") at the
     * selling branch. A name given fills a contact that had none.
     *
     * @return array{id: string, name: string, phone: string, points: int, purchases: int, last_purchase_on: string|null}|null Null without CRM or a valid number.
     */
    public function remember(Organization $company, string $unitId, ?string $name, string $phone, ?User $actor): ?array
    {
        if (! $this->available($company) || $this->contacts->phone($company, $phone) === null) {
            return null;
        }
        $found = $this->contacts->findByPhone($company, $phone);
        if ($found !== null) {
            return self::shape($found);
        }
        try {
            $contact = $this->contacts->create($company, $unitId, [
                'name' => trim((string) $name) !== '' ? mb_substr(trim((string) $name), 0, 150) : $this->contacts->phone($company, $phone),
                'phone' => $phone, 'source' => 'pos', 'kind' => 'person',
            ], $actor, requireFields: false);
        } catch (UniqueConstraintViolationException) {
            // Two counters added the same number at once: the other one won.
            $contact = $this->contacts->findByPhone($company, $phone);
        }

        return $contact === null ? null : self::shape($contact);
    }

    /**
     * A sale (+) or return (−) for a contact: what they spent, and points
     * earned or given back. Once per source (a sale retried changes nothing).
     */
    public function recordSale(Organization $company, string $contactId, string $sourceModule, string $sourceType, string $sourceId, int $signedTotalMinor, string $on, string $currency): void
    {
        if (! $this->available($company) || ! $this->crm->query(Contact::class, $company)->whereKey($contactId)->exists()) {
            return;
        }
        $rate = (int) $this->rules->get('crm.loyalty_points_per_100', $this->contexts->forOrganization($company));
        $per = 100 * 10 ** MoneyText::fractionDigits($currency);
        $points = $rate <= 0 ? 0 : intdiv(abs($signedTotalMinor) * $rate, $per) * ($signedTotalMinor < 0 ? -1 : 1);
        try {
            $this->crm->transaction($company, function () use ($company, $contactId, $sourceModule, $sourceType, $sourceId, $signedTotalMinor, $on, $points) {
                $entry = new Points;
                $entry->fill(['organization_id' => $company->getKey(), 'contact_id' => $contactId, 'points' => $points, 'reason' => $signedTotalMinor < 0 ? 'returned' : 'earned',
                    'source_module' => $sourceModule, 'source_type' => $sourceType, 'source_id' => $sourceId]);
                $entry->save();
                $this->contacts->recordPurchase($company, $contactId, $signedTotalMinor, $on);
                if ($points !== 0) {
                    $this->crm->query(Contact::class, $company)->whereKey($contactId)->increment('points', $points);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Already recorded.
        }
    }

    /**
     * @return array{id: string, name: string, phone: string, points: int, purchases: int, last_purchase_on: string|null}
     */
    private static function shape(Contact $contact): array
    {
        return ['id' => $contact->getKey(), 'name' => $contact->name, 'phone' => (string) $contact->phone, 'points' => (int) $contact->points,
            'purchases' => (int) $contact->purchases, 'last_purchase_on' => $contact->last_purchase_on?->toDateString()];
    }
}
