<?php

namespace Modules\Pharmacy\Contracts;

use App\Platform\Tenancy\Models\Organization;

/**
 * How other modules (outpatient prescriptions, inpatient orders) pick a
 * medicine from the pharmacy's list, never through the pharmacy tables:
 * medicines in use, by brand name, generic name, SKU or barcode. No prices
 * or stock.
 */
interface MedicineCatalogue
{
    /**
     * @return list<array{id: string, name: string, generic: string, strength: ?string, form: string}>
     */
    public function search(Organization $company, string $term, int $limit = 15): array;

    /**
     * The medicines among these ids that are in use, with their label ("Napa 500 mg").
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function labels(Organization $company, array $ids): array;
}
