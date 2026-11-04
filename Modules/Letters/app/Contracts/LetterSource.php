<?php

namespace Modules\Letters\Contracts;

use App\Platform\Tenancy\Models\Organization;

/**
 * A record of another module letters are issued from (Payroll: a salary
 * increment round). Letters knows only this contract. A source tags itself
 * "letters.sources" in its service provider; it answers null where its
 * module is off, the record is not the company's, or not ready yet. The
 * same {type, id} goes to every LetterFieldSource as the context, so fields
 * can be filled from the record (the round's new salary).
 */
interface LetterSource
{
    /** The type in addresses and on letters ("payroll.increment_round"). */
    public function type(): string;

    /**
     * The record's title in the language, the letter kind it suits, and the
     * employees letters are for; null when it cannot be used (with why).
     *
     * @return array{title: string, kind: string, employee_ids: list<string>}|array{refused: string}|null
     */
    public function people(Organization $company, string $id, string $locale): ?array;
}
