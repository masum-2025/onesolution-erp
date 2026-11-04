<?php

namespace Modules\Letters\Contracts;

use App\Platform\Tenancy\Models\Organization;

/**
 * What another module puts in letters (HRM: the employee; Payroll: their
 * pay). Letters knows only this contract, never the module. A source tags
 * itself "letters.field_sources" in its service provider and answers
 * nothing where its module is off. Salary fields are marked: only people
 * with letters.salary use them, and a company can switch them off.
 */
interface LetterFieldSource
{
    /**
     * The fields it fills, in groups, labelled in the given language. A key
     * is "{group}.{name}" (lower case); "table" fields fill a table block
     * (rows of label and value) instead of text.
     *
     * @return list<array{group: string, label: string, fields: list<array{key: string, label: string, salary: bool, table?: bool, sample: string}>}>
     */
    public function fields(Organization $company, string $locale): array;

    /**
     * Each employee's values in the language: text, or rows for a table
     * field. A field the employee has no value for is left out.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, string|list<array{0: string, 1: string}>>>
     */
    public function values(Organization $company, array $employeeIds, string $locale): array;
}
