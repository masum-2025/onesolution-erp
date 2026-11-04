<?php

namespace Modules\Letters\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Letters\Contracts\LetterFieldSource;

/**
 * Every {placeholder} a letter may hold: the letter's own (its number and
 * date, the company's name and address), the fields other modules give
 * (LetterFieldSource: HRM's employee, Payroll's pay) and what the template
 * asks when issuing ({input.key}). Salary fields only where the company
 * allows them (rule letters.salary_fields_enabled) and for people with
 * letters.salary.
 */
class LetterFields
{
    /** The letter's own fields (filled by Letters itself). */
    public const OWN = ['letter.number', 'letter.date', 'company.name', 'company.address'];

    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts) {}

    /** Whether the company allows salary in letters at all. */
    public function salaryEnabled(Organization $company): bool
    {
        return (bool) $this->rules->get('letters.salary_fields_enabled', $this->contexts->forOrganization($company));
    }

    /** Whether the person in the context may put salary in letters here. */
    public function salaryAllowed(Organization $company): bool
    {
        return $this->salaryEnabled($company) && Gate::allows('letters.salary', $company);
    }

    /**
     * The fields in groups for the editor, in a language (salary ones only when allowed).
     *
     * @return list<array{group: string, label: string, fields: list<array<string, mixed>>}>
     */
    public function catalog(Organization $company, string $locale, ?bool $withSalary = null): array
    {
        $withSalary ??= $this->salaryAllowed($company);
        $own = [
            'group' => 'letter',
            'label' => __('letters::letters.fields.group_letter', [], $locale),
            'fields' => array_map(fn (string $key) => [
                'key' => $key, 'label' => __('letters::letters.fields.'.str_replace('.', '_', $key), [], $locale), 'salary' => false,
                'sample' => __('letters::letters.fields.sample_'.str_replace('.', '_', $key), [], $locale),
            ], self::OWN),
        ];
        $groups = [$own];
        foreach ($this->sources() as $source) {
            foreach ($source->fields($company, $locale) as $group) {
                $fields = array_values(array_filter($group['fields'], fn (array $field) => $withSalary || ! $field['salary']));
                if ($fields !== []) {
                    $groups[] = [...$group, 'fields' => $fields];
                }
            }
        }

        return $groups;
    }

    /**
     * Every known field by key: whether it is salary and whether it fills a table.
     *
     * @return array<string, array{salary: bool, table: bool, label: string}>
     */
    public function known(Organization $company, string $locale): array
    {
        $known = [];
        foreach ($this->catalog($company, $locale, true) as $group) {
            foreach ($group['fields'] as $field) {
                $known[$field['key']] = ['salary' => (bool) $field['salary'], 'table' => (bool) ($field['table'] ?? false), 'label' => (string) $field['label']];
            }
        }

        return $known;
    }

    /**
     * The values other modules give for these employees in a language.
     *
     * @param  list<string>  $employeeIds
     * @param  array{type?: string, id?: string}  $context  The record letters are issued from (LetterSource).
     * @return array<string, array<string, string|list<array{0: string, 1: string}>>>
     */
    public function values(Organization $company, array $employeeIds, string $locale, array $context = []): array
    {
        $values = [];
        foreach ($this->sources() as $source) {
            foreach ($source->values($company, $employeeIds, $locale, $context) as $employeeId => $fields) {
                $values[$employeeId] = [...($values[$employeeId] ?? []), ...$fields];
            }
        }

        return $values;
    }

    /**
     * @return iterable<LetterFieldSource>
     */
    private function sources(): iterable
    {
        return app()->tagged('letters.field_sources');
    }
}
