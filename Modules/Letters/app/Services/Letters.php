<?php

namespace Modules\Letters\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Letters\Exceptions\LettersException;
use Modules\Letters\Models\Letter;
use Modules\Letters\Models\LetterSequence;
use Modules\Letters\Models\LetterTemplate;

/**
 * Issuing letters from an active design to one employee or many at once.
 *
 * - The people are HRM's (EmployeeDirectory), in the unit or below it.
 * - Every {placeholder} is filled in the language chosen: the letter's own
 *   (number, date, company), the fields other modules give, and what the
 *   design asks ({input.key}). If anyone lacks a value the design uses,
 *   nothing is issued and the message names them and the fields.
 * - Each letter is numbered (rule letters.number_format, counted per
 *   design code and year) and kept as it was issued: later changes to the
 *   design or the employee never change it. Revoked with a reason, never
 *   deleted or edited.
 * - Salary designs are issued only where the company allows salary in
 *   letters and by people with letters.salary.
 * - The same op_id (a retry, an offline send) gives the letters already
 *   issued instead of new ones.
 * - Employees whose letter is shared with them are told by mail (no
 *   content in the mail) and see it in the app or the portal.
 */
class Letters
{
    public const MAX_PEOPLE = 200;

    public function __construct(
        private Place $place,
        private LetterFields $fields,
        private LetterLayout $layout,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{template_id: string, employee_ids: list<string>, locale: string, inputs?: array<string, string|null>, shared?: bool, op_id: string}  $data
     * @return list<Letter>
     */
    public function issue(Organization $unit, Organization $company, array $data, User $actor): array
    {
        $done = $this->place->query(Letter::class, $company)->where('op_id', $data['op_id'])->orderBy('number')->get();
        if ($done->isNotEmpty()) {
            return $done->all();
        }
        $template = $this->place->query(LetterTemplate::class, $company)->whereKey($data['template_id'])->first() ?? throw LettersException::templateNotFound();
        if ($template->status !== LetterTemplate::ACTIVE) {
            throw LettersException::notActive();
        }
        if ($template->uses_salary && ! $this->fields->salaryEnabled($company)) {
            throw LettersException::salaryOff();
        }
        if ($template->uses_salary && ! $this->fields->salaryAllowed($company)) {
            throw LettersException::salaryNotAllowed();
        }
        $locale = $data['locale'];
        $blocks = $template->bodies[$locale] ?? throw ValidationException::withMessages(['locale' => __('letters::letters.validation.locale')]);
        $employees = $this->employees($unit, $company, $data['employee_ids']);
        $inputs = $this->inputs($template, (array) ($data['inputs'] ?? []), $locale);

        $used = [];
        $tables = [];
        foreach ($blocks as $block) {
            $used = [...$used, ...$this->layout->placeholdersOf($block)];
            if ($block['type'] === 'table' && isset($block['field'])) {
                $tables[] = $block['field'];
            }
        }
        $used = array_values(array_unique($used));

        $given = $this->fields->values($company, array_keys($employees), $locale);
        $today = CarbonImmutable::now('UTC');
        $address = $template->letterhead['address'][$locale] ?? $template->letterhead['address']['en'] ?? null;
        $common = [
            'letter.date' => $today->locale($locale)->isoFormat('LL'),
            'company.name' => $company->displayName($locale),
            ...(filled($address) ? ['company.address' => $address] : []),
            ...$inputs,
        ];

        $missing = [];
        foreach ($employees as $id => $employee) {
            $values = [...($given[$id] ?? []), ...$common, 'letter.number' => '-'];
            // Inputs are checked on their own (an optional one may be empty).
            $lacks = array_values(array_filter([...$used, ...$tables], fn (string $key) => ! str_starts_with($key, 'input.')
                && (! isset($values[$key]) || $values[$key] === '' || $values[$key] === [])));
            if ($lacks !== []) {
                $known = $this->fields->known($company, $locale);
                $missing[] = $employee->name.' ('.implode(', ', array_map(fn (string $key) => $known[$key]['label'] ?? $key, $lacks)).')';
            }
        }
        if ($missing !== []) {
            throw LettersException::missingValues(implode('; ', array_slice($missing, 0, 10)).(count($missing) > 10 ? ' …' : ''));
        }

        $shared = (bool) ($data['shared'] ?? true);
        $letters = $this->place->transaction($company, function () use ($unit, $company, $template, $employees, $given, $common, $blocks, $locale, $shared, $data, $today, $address, $actor) {
            $letters = [];
            foreach ($employees as $id => $employee) {
                $number = $this->nextNumber($company, $template->code, (int) $today->format('Y'));
                $values = [...($given[$id] ?? []), ...$common, 'letter.number' => $number];
                $letter = new Letter;
                $letter->fill([
                    'organization_id' => $company->getKey(), 'unit_id' => $employee->unitId, 'employee_id' => $id,
                    'employee_code' => $employee->code, 'employee_name' => $employee->name,
                    'template_id' => $template->getKey(), 'template_version' => $template->version, 'kind' => $template->kind,
                    'number' => $number, 'locale' => $locale, 'title' => mb_substr($template->textIn('name', $locale), 0, 200),
                    'content' => [
                        'page' => $template->page,
                        'letterhead' => [...$template->letterhead, 'address' => $address, 'company' => $company->displayName($locale)],
                        'blocks' => array_map(fn (array $block) => $this->fill($block, $values), $blocks),
                    ],
                    'uses_salary' => $template->uses_salary, 'shared' => $shared,
                    'issued_by' => $actor->getKey(), 'issued_at' => $today, 'op_id' => $data['op_id'],
                ]);
                $letter->save();
                $this->audit->record('letters.issued', $letter, new: $letter->only(['number', 'kind', 'employee_code', 'locale', 'template_version', 'shared']),
                    actor: $actor, organizationId: $company->getKey());
                $letters[] = $letter;
            }

            return $letters;
        });

        if ($shared && $this->employeesSeeOwn($company)) {
            $this->tell($company, $letters, $employees);
        }

        return $letters;
    }

    public function revoke(Organization $company, Letter $letter, string $reason, User $actor): Letter
    {
        return $this->place->transaction($company, function () use ($company, $letter, $reason, $actor) {
            /** @var Letter $letter */
            $letter = $this->place->query(Letter::class, $company)->whereKey($letter->getKey())->lockForUpdate()->firstOrFail();
            if ($letter->revoked_at !== null) {
                throw LettersException::alreadyRevoked();
            }
            $letter->forceFill(['revoked_at' => CarbonImmutable::now('UTC'), 'revoked_by' => $actor->getKey(), 'revoke_reason' => trim($reason)])->save();
            $this->audit->record('letters.revoked', $letter, old: ['revoked' => false], new: ['revoked' => true, 'number' => $letter->number, 'reason' => $letter->revoke_reason],
                actor: $actor, organizationId: $company->getKey());

            return $letter;
        });
    }

    /** Whether employees see the letters shared with them (rule letters.employees_see_own). */
    public function employeesSeeOwn(Organization $company): bool
    {
        return (bool) $this->rules->get('letters.employees_see_own', $this->contexts->forOrganization($company));
    }

    /**
     * What a design would look like for one employee (or with the examples when none), not saved.
     *
     * @param  array<string, string|null>  $inputs
     * @return array<string, mixed>
     */
    public function preview(Organization $unit, Organization $company, LetterTemplate $template, string $locale, ?string $employeeId, array $inputs): array
    {
        $blocks = $template->bodies[$locale] ?? throw ValidationException::withMessages(['locale' => __('letters::letters.validation.locale')]);
        $values = [];
        foreach ($this->fields->catalog($company, $locale, true) as $group) {
            foreach ($group['fields'] as $field) {
                $values[$field['key']] = ($field['table'] ?? false) ? [[$field['label'], $field['sample']]] : $field['sample'];
            }
        }
        if ($employeeId !== null) {
            $employee = $this->employees($unit, $company, [$employeeId])[$employeeId];
            $values = [...$values, ...($this->fields->values($company, [$employee->id], $locale)[$employee->id] ?? [])];
        }
        $address = $template->letterhead['address'][$locale] ?? $template->letterhead['address']['en'] ?? null;
        $values = [...$values, 'company.name' => $company->displayName($locale), 'company.address' => (string) $address,
            'letter.date' => CarbonImmutable::now('UTC')->locale($locale)->isoFormat('LL')];
        foreach ((array) $template->inputs as $input) {
            $values['input.'.$input['key']] = filled($inputs[$input['key']] ?? null) ? $this->inputText($input, (string) $inputs[$input['key']], $locale) : '['.($input['label'][$locale] ?? $input['label']['en']).']';
        }

        return [
            'page' => $template->page,
            'letterhead' => [...$template->letterhead, 'address' => $address, 'company' => $company->displayName($locale)],
            'blocks' => array_map(fn (array $block) => $this->fill($block, $values), $blocks),
        ];
    }

    /**
     * The employees by id, each in the unit or below it; anyone else is refused.
     *
     * @param  list<string>  $ids
     * @return array<string, EmployeeRecord>
     */
    private function employees(Organization $unit, Organization $company, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === [] || count($ids) > self::MAX_PEOPLE) {
            throw ValidationException::withMessages(['employee_ids' => __('letters::letters.validation.people', ['max' => self::MAX_PEOPLE])]);
        }
        $units = $this->place->subtreeIds($unit);
        $found = array_filter($this->directory->many($company, $ids), fn (EmployeeRecord $employee) => in_array($employee->unitId, $units, true));
        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['employee_ids' => __('letters::letters.validation.employee')]);
        }

        return $found;
    }

    /**
     * The design's inputs as text in the language: required ones must be given.
     *
     * @param  array<string, string|null>  $given
     * @return array<string, string>
     */
    private function inputs(LetterTemplate $template, array $given, string $locale): array
    {
        $values = [];
        $errors = [];
        $keys = array_column((array) $template->inputs, 'key');
        foreach (array_diff(array_keys($given), $keys) as $unknown) {
            $errors["inputs.{$unknown}"] = __('letters::letters.validation.unknown_input');
        }
        foreach ((array) $template->inputs as $input) {
            $value = trim((string) ($given[$input['key']] ?? ''));
            $label = $input['label'][$locale] ?? $input['label']['en'];
            if ($value === '') {
                if ($input['required']) {
                    $errors["inputs.{$input['key']}"] = __('letters::letters.validation.input_required', ['name' => $label]);
                }
                $values['input.'.$input['key']] = '';

                continue;
            }
            $max = $input['type'] === 'long_text' ? 2000 : 200;
            if (mb_strlen($value) > $max || ($input['type'] === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1)) {
                $errors["inputs.{$input['key']}"] = __('letters::letters.validation.input_value', ['name' => $label]);

                continue;
            }
            $values['input.'.$input['key']] = $this->inputText($input, $value, $locale);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /** @param  array<string, mixed>  $input */
    private function inputText(array $input, string $value, string $locale): string
    {
        return $input['type'] === 'date' ? CarbonImmutable::parse($value, 'UTC')->locale($locale)->isoFormat('LL') : $value;
    }

    /**
     * A block with its placeholders filled (table fields become rows).
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string|list<array{0: string, 1: string}>>  $values
     * @return array<string, mixed>
     */
    private function fill(array $block, array $values): array
    {
        if ($block['type'] === 'table' && isset($block['field'])) {
            $rows = $values[$block['field']] ?? [];

            return ['type' => 'table', 'rows' => is_array($rows) ? array_values($rows) : []];
        }
        $replace = fn (string $text) => preg_replace_callback('/\{([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)\}/',
            fn (array $match) => is_string($values[$match[1]] ?? null) ? $values[$match[1]] : $match[0], $text);
        foreach (['text', 'name', 'title'] as $key) {
            if (isset($block[$key]) && is_string($block[$key])) {
                $block[$key] = $replace($block[$key]);
            }
        }
        if (isset($block['items'])) {
            $block['items'] = array_map($replace, $block['items']);
        }
        if (isset($block['rows'])) {
            $block['rows'] = array_map(fn (array $row) => [$replace($row[0]), $replace($row[1])], $block['rows']);
        }

        return $block;
    }

    /** The next number for a design code this year (call inside the transaction: the counter is locked). */
    private function nextNumber(Organization $company, string $code, int $year): string
    {
        $sequence = $this->place->query(LetterSequence::class, $company)->where('code', $code)->where('year', $year)->lockForUpdate()->first();
        if ($sequence === null) {
            $sequence = new LetterSequence;
            $sequence->fill(['organization_id' => $company->getKey(), 'code' => $code, 'year' => $year, 'last' => 0]);
        }
        $sequence->last++;
        $sequence->save();
        $format = (string) $this->rules->get('letters.number_format', $this->contexts->forOrganization($company));

        return preg_replace_callback('/\{(CODE|YYYY|YY|SEQ(?::(\d))?)\}/', fn (array $match) => match (true) {
            $match[1] === 'CODE' => $code,
            $match[1] === 'YYYY' => (string) $year,
            $match[1] === 'YY' => substr((string) $year, -2),
            default => str_pad((string) $sequence->last, (int) ($match[2] ?? 4), '0', STR_PAD_LEFT),
        }, $format);
    }

    /**
     * The people told by mail that a letter is waiting (no content in the mail).
     *
     * @param  list<Letter>  $letters
     * @param  array<string, EmployeeRecord>  $employees
     */
    private function tell(Organization $company, array $letters, array $employees): void
    {
        $users = User::query()->whereKey(collect($employees)->pluck('userId')->filter()->values()->all())->get();
        if ($users->isEmpty()) {
            return;
        }
        $title = $letters[0]->title;
        $this->notifier->notify(
            'letters.issued',
            $users,
            fn (string $locale) => ['organization' => $company->displayName($locale), 'title' => $title],
            $company->partner,
            $company,
        );
    }
}
