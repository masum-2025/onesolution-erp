<?php

namespace Modules\Payroll\Contracts;

use App\Platform\Tenancy\Models\Organization;

/**
 * Appraisal results another module keeps (Appraisals): Payroll reads them
 * to suggest salary increments by band, and knows only this contract,
 * never the module. A source tags itself "payroll.performance_sources" in
 * its service provider and answers nothing where its module is off. Only
 * the score and band leave the source: no comments, goals or ratings.
 */
interface PerformanceSource
{
    /**
     * Finished cycles of the company, newest first: an id, a name in each
     * language, and the period reviewed ("YYYY-MM-DD").
     *
     * @return list<array{id: string, name: array<string, string>, period_from: string, period_to: string}>
     */
    public function cycles(Organization $company): array;

    /**
     * The company's bands, best first: a key and a name in each language.
     *
     * @return list<array{key: string, name: array<string, string>}>
     */
    public function bands(Organization $company): array;

    /**
     * Each employee's finished result in one of this source's cycles: the
     * score per mille and the band key. Unknown cycle: an empty list.
     *
     * @return array<string, array{score: int|null, band: string|null}>
     */
    public function results(Organization $company, string $cycleId): array;
}
