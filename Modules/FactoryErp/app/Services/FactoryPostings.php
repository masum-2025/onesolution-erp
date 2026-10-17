<?php

namespace Modules\FactoryErp\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Accounting\Ledger\LedgerEntry;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Accounting\Services\Ledger;

/**
 * The factory's costs into the books, only where the company keeps them
 * (Accounting on), through Accounting's public Ledger; the op id makes a
 * posting happen once. Materials on the floor are work in progress
 * (posting key factory_erp.wip) until finished goods come in.
 */
class FactoryPostings
{
    public const WIP = 'factory_erp.wip';

    /** Income from goods shipped to buyers (the draft invoice's lines). */
    public const EXPORT_SALES = 'factory_erp.export_sales';

    public function __construct(private ModuleResolver $modules) {}

    /**
     * Materials issued (value above zero: work in progress up, stock down) or returned (below zero).
     */
    public function materials(Organization $company, string $opId, CarbonImmutable $date, string $narration, string $sourceType, string $sourceId, string $currency, int $value, ?string $costCentre): ?string
    {
        if ($value === 0 || ! $this->modules->isEnabled('accounting', $company)) {
            return null;
        }
        $lines = $value > 0
            ? [LedgerLine::debit(self::WIP, $value, $costCentre), LedgerLine::credit('inventory.stock', $value, $costCentre)]
            : [LedgerLine::debit('inventory.stock', -$value, $costCentre), LedgerLine::credit(self::WIP, -$value, $costCentre)];

        return $this->post($company, $opId, $date, $narration, $sourceType, $sourceId, $currency, $lines);
    }

    /** Finished goods shipped: cost of sales up, stock down, by what they cost. */
    public function sold(Organization $company, string $opId, CarbonImmutable $date, string $narration, string $sourceId, string $currency, int $value, ?string $costCentre): ?string
    {
        if ($value <= 0 || ! $this->modules->isEnabled('accounting', $company)) {
            return null;
        }

        return $this->post($company, $opId, $date, $narration, 'shipment', $sourceId, $currency,
            [LedgerLine::debit('inventory.cogs', $value, $costCentre), LedgerLine::credit('inventory.stock', $value, $costCentre)]);
    }

    /** Finished pieces into the store: stock up, work in progress down, by what they cost. */
    public function finished(Organization $company, string $opId, CarbonImmutable $date, string $narration, string $sourceId, string $currency, int $value, ?string $costCentre): ?string
    {
        if ($value <= 0 || ! $this->modules->isEnabled('accounting', $company)) {
            return null;
        }

        return $this->post($company, $opId, $date, $narration, 'receipt', $sourceId, $currency,
            [LedgerLine::debit('inventory.stock', $value, $costCentre), LedgerLine::credit(self::WIP, $value, $costCentre)]);
    }

    /** @param  list<LedgerLine>  $lines */
    private function post(Organization $company, string $opId, CarbonImmutable $date, string $narration, string $sourceType, string $sourceId, string $currency, array $lines): string
    {
        return app(Ledger::class)->post($company, new LedgerEntry(
            opId: $opId,
            entryDate: $date->toDateString(),
            narration: $narration,
            sourceModule: 'factory_erp',
            sourceType: $sourceType,
            sourceId: $sourceId,
            currency: $currency,
            lines: $lines,
        ))->id;
    }
}
