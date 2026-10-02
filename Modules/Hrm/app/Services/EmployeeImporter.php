<?php

namespace Modules\Hrm\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Enums\ImportRowStatus;
use Modules\Hrm\Enums\ImportStatus;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Http\Requests\HireEmployeeRequest;
use Modules\Hrm\Jobs\ImportEmployees;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\EmployeeImport;
use Modules\Hrm\Models\ImportRow;
use Modules\Hrm\Models\Position;
use Throwable;

/**
 * Employees from a CSV file, in two steps: check (every row, nothing saved
 * but the findings) and import (each valid row hired through
 * EmployeeLifecycle, so rules, codes, audit and events are the same as hiring
 * one person). The file is never stored; row details are encrypted and wiped
 * when the import ends. Limits are the rules hrm.import_max_rows / _kb.
 */
class EmployeeImporter
{
    /** Columns of the file; the first three must be in the header. */
    public const COLUMNS = [
        'full_name', 'employment_type', 'joined_on',
        'full_name_local', 'phone', 'email', 'date_of_birth', 'gender', 'national_id', 'tax_id',
        'position_code', 'unit_code', 'manager_code',
        'address_line1', 'address_city', 'address_district',
        'emergency_name', 'emergency_relation', 'emergency_phone',
    ];

    public const REQUIRED_COLUMNS = ['full_name', 'employment_type', 'joined_on'];

    public const CUSTOM_PREFIX = 'custom_';

    /** Where an error on a hire field is shown in the file. */
    private const COLUMN_OF = [
        'position_id' => 'position_code',
        'manager_id' => 'manager_code',
        'organization_id' => 'unit_code',
        'address' => 'address_line1',
        'address.line1' => 'address_line1',
        'address.city' => 'address_city',
        'address.district' => 'address_district',
        'emergency_contact' => 'emergency_name',
        'emergency_contact.name' => 'emergency_name',
        'emergency_contact.relation' => 'emergency_relation',
        'emergency_contact.phone' => 'emergency_phone',
    ];

    public function __construct(
        private EmployeeLifecycle $lifecycle,
        private CustomFields $customFields,
        private Units $units,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private TenantDatabases $databases,
        private AuditLogger $audit,
    ) {}

    /**
     * The columns a file for $unit may have (extra fields included), and the limits.
     *
     * @return array{columns: list<array{key: string, required: bool, label: string|null}>, max_rows: int, max_kb: int}
     */
    public function columns(Organization $unit): array
    {
        $columns = array_map(fn (string $key) => ['key' => $key, 'required' => in_array($key, self::REQUIRED_COLUMNS, true), 'label' => null], self::COLUMNS);
        foreach ($this->customFields->applicableTo($unit) as $field) {
            $columns[] = ['key' => self::CUSTOM_PREFIX.$field->key, 'required' => false, 'label' => $field->textIn('label')];
        }

        $context = $this->contexts->forOrganization($unit);

        return [
            'columns' => $columns,
            'max_rows' => (int) $this->rules->get('hrm.import_max_rows', $context),
            'max_kb' => (int) $this->rules->get('hrm.import_max_kb', $context),
        ];
    }

    /**
     * Reads and checks the file. Problems with the file as a whole are a
     * validation error on "file"; problems of single rows are kept per row.
     */
    public function check(Organization $unit, UploadedFile $file, User $actor): EmployeeImport
    {
        $company = $this->units->companyOf($unit);
        $limits = $this->columns($unit);
        [$header, $lines] = $this->read($file, $limits['max_kb'], $limits['max_rows'], array_column($limits['columns'], 'key'));

        $lookups = $this->lookups($unit, $company);
        $checked = [];
        $nationalIds = [];
        foreach ($lines as $rowNo => $cells) {
            [$data, $errors] = $this->row($header, $cells, $unit, $lookups);

            $hash = $errors === [] ? Employee::hashOf($data['national_id'] ?? null) : null;
            if ($hash !== null) {
                if (isset($nationalIds[$hash])) {
                    $errors['national_id'] = __('hrm::hrm.validation.import_duplicate_in_file', ['row' => $nationalIds[$hash]]);
                } else {
                    $nationalIds[$hash] = $rowNo;
                }
            }

            $checked[$rowNo] = [$data, $errors];
        }

        $invalid = count(array_filter($checked, fn (array $row) => $row[1] !== []));

        $import = $this->transaction($company, function () use ($unit, $company, $file, $actor, $checked, $invalid) {
            $import = EmployeeImport::query()->create([
                'organization_id' => $unit->getKey(),
                'company_id' => $company->getKey(),
                'created_by' => $actor->getKey(),
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 190),
                'status' => ImportStatus::Checked,
                'total_rows' => count($checked),
                'invalid_rows' => $invalid,
            ]);

            foreach ($checked as $rowNo => [$data, $errors]) {
                ImportRow::query()->create([
                    'organization_id' => $unit->getKey(),
                    'import_id' => $import->getKey(),
                    'row_no' => $rowNo,
                    'data' => $data,
                    'errors' => $errors === [] ? null : $errors,
                    'status' => $errors === [] ? ImportRowStatus::Valid : ImportRowStatus::Invalid,
                ]);
            }

            return $import;
        });

        $this->audit->record('hrm.import_checked', $import, new: ['rows' => count($checked), 'invalid_rows' => $invalid], actor: $actor, organizationId: $unit->getKey());

        return $import;
    }

    /**
     * Queues the import. With invalid rows it runs only when told to skip them.
     */
    public function start(EmployeeImport $import, bool $skipInvalid, User $actor): EmployeeImport
    {
        if ($import->status !== ImportStatus::Checked) {
            throw HrmException::importNotReady(__('hrm::hrm.import_statuses.'.$import->status->value));
        }
        if ($import->invalid_rows > 0 && ! $skipInvalid) {
            throw HrmException::importHasInvalid();
        }
        if ($import->total_rows - $import->invalid_rows === 0) {
            throw HrmException::importHasInvalid();
        }

        $busy = EmployeeImport::query()->withoutGlobalScope(OrganizationScope::class)
            ->where('company_id', $import->company_id)
            ->whereIn('status', [ImportStatus::Queued->value, ImportStatus::Running->value])
            ->exists();
        if ($busy) {
            throw HrmException::importBusy();
        }

        $import->forceFill(['status' => ImportStatus::Queued])->save();
        $this->audit->record('hrm.import_started', $import, new: ['rows' => $import->total_rows - $import->invalid_rows, 'skipped' => $import->invalid_rows], actor: $actor, organizationId: $import->organization_id);

        ImportEmployees::dispatch($import->getKey(), $import->organization_id, $import->company_id, $actor->getKey());

        return $import;
    }

    /** A checked import, or a queued one that never ran (HRM switched off meanwhile). */
    public function cancel(EmployeeImport $import, User $actor): EmployeeImport
    {
        if (! in_array($import->status, [ImportStatus::Checked, ImportStatus::Queued], true)) {
            throw HrmException::importNotReady(__('hrm::hrm.import_statuses.'.$import->status->value));
        }

        $this->finish($import, ImportStatus::Cancelled);
        $this->audit->record('hrm.import_cancelled', $import, actor: $actor, organizationId: $import->organization_id);

        return $import;
    }

    /**
     * Hires every valid row not hired yet (safe to run again after a crash:
     * a row and its employee are saved together). Called by ImportEmployees
     * inside the starter's context, with their permission checked.
     */
    public function run(EmployeeImport $import, User $actor): void
    {
        if (! in_array($import->status, [ImportStatus::Queued, ImportStatus::Running], true)) {
            return;
        }

        $import->forceFill(['status' => ImportStatus::Running, 'started_at' => $import->started_at ?? now()])->save();
        $company = Organization::query()->findOrFail($import->company_id);
        $connection = $this->databases->forOrganization($company);

        $import->rows()->where('status', ImportRowStatus::Valid->value)->orderBy('row_no')->each(function (ImportRow $row) use ($actor, $connection) {
            $data = (array) $row->data;
            $unit = Organization::query()->find($data['organization_id'] ?? null);

            try {
                DB::connection($connection)->transaction(function () use ($row, $data, $unit, $actor) {
                    $employee = $this->lifecycle->hire($unit ?? throw HrmException::notCompanyUnit(), $data, $actor);
                    $row->forceFill(['status' => ImportRowStatus::Imported, 'employee_id' => $employee->getKey(), 'data' => null])->save();
                });
            } catch (ValidationException $exception) {
                $row->forceFill(['status' => ImportRowStatus::Failed, 'errors' => $this->columnErrors(array_map(fn (array $messages) => (string) $messages[0], $exception->errors()))])->save();
            } catch (HrmException $exception) {
                $row->forceFill(['status' => ImportRowStatus::Failed, 'errors' => ['row' => $exception->getMessage()]])->save();
            }
        }, 100);

        $import->rows()->where('status', ImportRowStatus::Invalid->value)->update(['status' => ImportRowStatus::Skipped->value]);
        $this->finish($import, ImportStatus::Done);

        $this->audit->record('hrm.import_finished', $import, new: [
            'imported_rows' => $import->imported_rows, 'failed_rows' => $import->failed_rows, 'skipped_rows' => $import->invalid_rows,
        ], actor: $actor, organizationId: $import->organization_id);
    }

    /**
     * Ends an import: counts settled, every row's details wiped.
     */
    public function finish(EmployeeImport $import, ImportStatus $status): void
    {
        $import->rows()->update(['data' => null]);
        $import->forceFill([
            'status' => $status,
            'imported_rows' => $import->rows()->where('status', ImportRowStatus::Imported->value)->count(),
            'failed_rows' => $import->rows()->where('status', ImportRowStatus::Failed->value)->count(),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param  list<string>  $known
     * @return array{0: list<string>, 1: array<int, list<string>>} Header keys and data lines by row number (header = 1).
     */
    private function read(UploadedFile $file, int $maxKb, int $maxRows, array $known): array
    {
        $fail = fn (string $key, array $replace = []) => throw ValidationException::withMessages(['file' => __("hrm::hrm.validation.{$key}", $replace)]);

        if ($file->getSize() > $maxKb * 1024) {
            $fail('import_file_size', ['max' => $maxKb]);
        }
        $content = (string) file_get_contents($file->getRealPath());
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            $fail('import_encoding');
        }
        if (str_contains($content, "\0")) {
            $fail('import_file');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $candidate) => substr_count($firstLine, $candidate))->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = null;
        $lines = [];
        $lineNo = 0;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $lineNo++;
            $cells = array_map(fn ($cell) => trim((string) $cell), $cells);
            if ($cells === [''] || implode('', $cells) === '') {
                continue;
            }
            if ($header === null) {
                $header = array_map(fn (string $cell) => mb_strtolower($cell), $cells);

                continue;
            }
            $lines[$lineNo] = $cells;
            if (count($lines) > $maxRows) {
                fclose($stream);
                $fail('import_too_many_rows', ['max' => $maxRows]);
            }
        }
        fclose($stream);

        if ($header === null || count(array_intersect($header, $known)) === 0) {
            $fail('import_file');
        }
        if (($missing = array_diff(self::REQUIRED_COLUMNS, $header)) !== []) {
            $fail('import_missing_columns', ['columns' => implode(', ', $missing)]);
        }
        if (($unknown = array_diff($header, $known)) !== []) {
            $fail('import_unknown_columns', ['columns' => implode(', ', array_slice($unknown, 0, 10))]);
        }
        if ($lines === []) {
            $fail('import_empty');
        }

        return [$header, $lines];
    }

    /**
     * One line as hire details, with what is wrong with it per column.
     *
     * @param  list<string>  $header
     * @param  list<string>  $cells
     * @param  array{units: array<string, string|null>, positions: array<string, list<Position>>, managers: array<string, string>}  $lookups
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function row(array $header, array $cells, Organization $importUnit, array $lookups): array
    {
        if (count($cells) !== count($header)) {
            return [[], ['row' => __('hrm::hrm.validation.import_row_length')]];
        }

        $cell = array_combine($header, $cells);
        $errors = [];
        foreach ($cell as $column => $value) {
            if ($value !== '' && in_array($value[0], ['=', '@'], true)) {
                $errors[$column] = __('hrm::hrm.validation.import_formula');
            }
        }
        if ($errors !== []) {
            return [[], $errors];
        }

        $get = fn (string $column) => ($cell[$column] ?? '') === '' ? null : $cell[$column];

        $unit = $importUnit;
        if (($code = $get('unit_code')) !== null) {
            $unitId = $lookups['units'][mb_strtoupper($code)] ?? null;
            $found = $unitId === null ? null : Organization::query()->find($unitId);
            if ($found === null) {
                $errors['unit_code'] = __('hrm::hrm.validation.import_unit_code');
            } else {
                $unit = $found;
            }
        }

        $data = array_filter([
            'organization_id' => $unit->getKey(),
            'full_name' => $get('full_name'),
            'full_name_local' => $get('full_name_local'),
            'employment_type' => $get('employment_type'),
            'joined_on' => $this->date($get('joined_on'), 'joined_on', $errors),
            'phone' => $get('phone'),
            'email' => $get('email'),
            'date_of_birth' => $this->date($get('date_of_birth'), 'date_of_birth', $errors),
            'gender' => $get('gender') === null ? null : mb_strtolower($get('gender')),
            'national_id' => $get('national_id'),
            'tax_id' => $get('tax_id'),
            'address' => array_filter(['line1' => $get('address_line1'), 'city' => $get('address_city'), 'district' => $get('address_district')]) ?: null,
            'emergency_contact' => array_filter(['name' => $get('emergency_name'), 'relation' => $get('emergency_relation'), 'phone' => $get('emergency_phone')]) ?: null,
        ], fn ($value) => $value !== null);

        if (($code = $get('position_code')) !== null) {
            $position = $this->positionFor($lookups['positions'][mb_strtolower($code)] ?? [], $unit);
            if ($position === null) {
                $errors['position_code'] = __('hrm::hrm.validation.import_position_code');
            } else {
                $data['position_id'] = $position->getKey();
            }
        }
        if (($code = $get('manager_code')) !== null) {
            $managerId = $lookups['managers'][mb_strtolower($code)] ?? null;
            if ($managerId === null) {
                $errors['manager_code'] = __('hrm::hrm.validation.import_manager_code');
            } else {
                $data['manager_id'] = $managerId;
            }
        }

        $custom = [];
        foreach ($cell as $column => $value) {
            if (str_starts_with($column, self::CUSTOM_PREFIX) && $value !== '') {
                $custom[substr($column, strlen(self::CUSTOM_PREFIX))] = $value;
            }
        }
        if ($custom !== []) {
            $data['custom'] = $custom;
        }

        $rules = HireEmployeeRequest::detailRules();
        $rules['full_name'] = ['required', ...$rules['full_name']];
        $rules['employment_type'] = ['required', ...$rules['employment_type']];
        $rules['joined_on'] = ['required', 'date'];
        $shape = Validator::make($data, $rules);
        if ($shape->fails()) {
            $errors = [...$this->columnErrors(array_map(fn (array $messages) => (string) $messages[0], $shape->errors()->toArray())), ...$errors];
        }

        if ($errors === []) {
            try {
                $errors = $this->columnErrors($this->lifecycle->hireErrors($unit, $data));
            } catch (HrmException $exception) {
                $errors = ['unit_code' => $exception->getMessage()];
            }
        }

        return [$data, $errors];
    }

    /**
     * Units, positions and managers of the company, looked up once per file.
     *
     * @return array{units: array<string, string|null>, positions: array<string, list<Position>>, managers: array<string, string>}
     */
    private function lookups(Organization $unit, Organization $company): array
    {
        $units = [];
        foreach (Organization::query()->subtreeOf($unit)->get() as $candidate) {
            $code = mb_strtoupper($this->units->codeOf($candidate));
            // The same code twice is no code at all: the row must say which unit.
            $units[$code] = array_key_exists($code, $units) ? null : $candidate->getKey();
        }

        $positions = [];
        $scope = [...$unit->ancestorIds(), ...Organization::query()->subtreeOf($unit)->pluck('id')->map(fn ($id) => (string) $id)->all()];
        foreach (Position::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $scope)->where('is_active', true)->whereNotNull('code')->get() as $position) {
            $positions[mb_strtolower((string) $position->code)][] = $position;
        }

        $managers = Employee::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', '!=', EmployeeStatus::Exited->value)
            ->pluck('id', 'employee_code')
            ->mapWithKeys(fn ($id, $code) => [mb_strtolower((string) $code) => (string) $id])
            ->all();

        return ['units' => $units, 'positions' => $positions, 'managers' => $managers];
    }

    /**
     * Of the positions with a code, the one nearest to the unit (its own, then above, then any).
     *
     * @param  list<Position>  $candidates
     */
    private function positionFor(array $candidates, Organization $unit): ?Position
    {
        $chain = [$unit->getKey(), ...array_reverse($unit->ancestorIds())];
        foreach ($chain as $id) {
            foreach ($candidates as $position) {
                if ($position->organization_id === $id) {
                    return $position;
                }
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function date(?string $text, string $column, array &$errors): ?string
    {
        if ($text === null) {
            return null;
        }

        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $text);
                if ($date !== null && $date->format(ltrim($format, '!')) === $text) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }
        $errors[$column] = __('hrm::hrm.validation.import_date');

        return null;
    }

    /**
     * Hire field errors named by the file's columns.
     *
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    private function columnErrors(array $errors): array
    {
        $named = [];
        foreach ($errors as $field => $message) {
            $column = self::COLUMN_OF[$field]
                ?? (str_starts_with($field, 'custom.') ? self::CUSTOM_PREFIX.substr($field, 7) : $field);
            $named[$column] ??= $message;
        }

        return $named;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(Organization $company, callable $callback): mixed
    {
        return DB::connection($this->databases->forOrganization($company))->transaction($callback);
    }
}
