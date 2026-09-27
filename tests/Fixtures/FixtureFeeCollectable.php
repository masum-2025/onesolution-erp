<?php

namespace Tests\Fixtures;

use App\Models\User;
use App\Platform\Payments\CollectionDue;
use App\Platform\Payments\Contracts\CollectableProvider;
use App\Platform\Payments\Models\Payment;
use App\Platform\Tenancy\Models\Organization;

/**
 * How a school module would let parents pay fees online: a fee is owed by
 * the parents it names, in the school's currency. Test-only (Phase 6); the
 * fees live in memory.
 */
class FixtureFeeCollectable implements CollectableProvider
{
    /** @var array<string, array{organization: string, payers: list<string>, amount: int, currency: string}> */
    public static array $fees = [];

    /** @var list<string> Payment ids marked paid. */
    public static array $paid = [];

    public function key(): string
    {
        return 'school.fee';
    }

    public function label(?string $locale = null): string
    {
        return 'Fee';
    }

    public function due(Organization $organization, string $id, User $payer): ?CollectionDue
    {
        $fee = self::$fees[$id] ?? null;
        if ($fee === null || $fee['organization'] !== $organization->getKey() || ! in_array($payer->getKey(), $fee['payers'], true)) {
            return null;
        }

        return new CollectionDue($fee['amount'], $fee['currency']);
    }

    public function paid(Payment $payment): void
    {
        self::$paid[] = $payment->getKey();
    }

    public function returnPath(Payment $payment): string
    {
        return "/portal/fees/{$payment->subject_id}";
    }
}
