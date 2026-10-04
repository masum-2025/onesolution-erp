<?php

namespace Modules\Attendance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Attendance\Models\DeviceFormat;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Punches from an attendance machine's file (fingerprint or card
 * terminals export CSV): which column holds the employee code and which the
 * time (one column, or a date and a time) and how times are written,
 * remembered for the company. Times are the company's local time. A line
 * already brought in counts once (op_id from code and time); codes not of
 * this unit, and days the person was not employed, are skipped and listed.
 * A time that cannot be read stops the whole file (the format is wrong).
 * The file is read in memory and never kept.
 */
class DeviceImports
{
    /** Most problem lines listed at once. */
    private const MAX_LISTED = 10;

    public function __construct(
        private Workplace $workplace,
        private Punches $punches,
        private Days $days,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{columns: array<string, string>, datetime_format: string}|null
     */
    public function format(Organization $company): ?array
    {
        $format = $this->workplace->query(DeviceFormat::class, $company)->first();

        return $format === null ? null : ['columns' => $format->columns, 'datetime_format' => $format->datetime_format];
    }

    /**
     * @param  array<string, string|null>  $columns  code, and datetime or date + time
     * @param  list<string>  $unitIds  The unit the import is for and the units below it.
     * @return array{added: int, already: int, unknown_codes: list<string>, not_employed: int}
     */
    public function import(Organization $company, Organization $unit, array $unitIds, UploadedFile $file, array $columns, string $format, User $actor): array
    {
        $context = $this->contexts->forOrganization($unit);
        [$header, $rows] = $this->read($file, (int) $this->rules->get('attendance.device_import_max_kb', $context), (int) $this->rules->get('attendance.device_import_max_rows', $context));
        $columns = array_filter($columns, fn ($name) => is_string($name) && trim($name) !== '');
        $at = $this->positions($header, $columns);

        $lines = [];
        $errors = [];
        foreach ($rows as $rowNo => $cells) {
            $cell = fn (string $key) => isset($at[$key]) ? trim((string) ($cells[$at[$key]] ?? '')) : '';
            $code = $cell('code');
            $text = isset($at['datetime']) ? $cell('datetime') : trim($cell('date').' '.$cell('time'));
            if ($code === '' && $text === '') {
                continue;
            }
            $instant = $this->instant($company, $text, $format);
            if ($code === '' || $instant === null) {
                $errors[] = __('attendance::attendance.device.row', ['row' => $rowNo, 'value' => mb_substr($text, 0, 30), 'format' => $format]);
                if (count($errors) >= self::MAX_LISTED) {
                    break;
                }

                continue;
            }
            $lines[] = [$code, $instant];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        $employees = $this->directory->byCodes($company, array_column($lines, 0));
        $result = ['added' => 0, 'already' => 0, 'unknown_codes' => [], 'not_employed' => 0];
        $touched = [];

        $this->workplace->transaction($company, function () use ($company, $unitIds, $lines, $employees, $columns, $format, $actor, &$result, &$touched) {
            foreach ($lines as [$code, $instant]) {
                $employee = $employees[$code] ?? null;
                if ($employee === null || ! in_array($employee->unitId, $unitIds, true)) {
                    $result['unknown_codes'][$code] = true;

                    continue;
                }
                $day = $this->workplace->dayOf($company, $instant);
                if (! $employee->employedOn($day)) {
                    $result['not_employed']++;

                    continue;
                }

                $opId = 'device-'.substr(hash('sha256', $employee->id.'|'.$instant->toIso8601String()), 0, 40);
                $punch = $this->punches->fromDevice($company, $employee, $instant, $opId, $actor);
                $punch->wasRecentlyCreated ? $result['added']++ : $result['already']++;
                $touched[$employee->id.'|'.$day->toDateString()] = [$employee, $instant];
            }

            $saved = $this->workplace->query(DeviceFormat::class, $company)->first() ?? new DeviceFormat(['organization_id' => $company->getKey()]);
            $saved->fill(['columns' => $columns, 'datetime_format' => $format])->save();
        });

        // Each day once, after all the lines (and the day before, for night shifts).
        foreach ($touched as [$employee, $instant]) {
            $this->days->touch($company, $employee, $instant);
        }

        $result['unknown_codes'] = array_slice(array_keys($result['unknown_codes']), 0, self::MAX_LISTED);
        $this->audit->record('attendance.device_imported', $company, new: [
            'added' => $result['added'], 'already' => $result['already'], 'not_employed' => $result['not_employed'], 'unknown_codes' => count($result['unknown_codes']),
        ], actor: $actor, organizationId: $company->getKey());

        return $result;
    }

    private function instant(Organization $company, string $text, string $format): ?CarbonImmutable
    {
        if ($text === '') {
            return null;
        }
        try {
            $local = CarbonImmutable::createFromFormat('!'.$format, $text, $this->workplace->timezone($company));
        } catch (InvalidFormatException) {
            return null;
        }
        $problems = CarbonImmutable::getLastErrors();
        if ($local === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            return null;
        }

        return $local->utc();
    }

    /**
     * @return array{0: list<string>, 1: array<int, list<string>>}
     */
    private function read(UploadedFile $file, int $maxKb, int $maxRows): array
    {
        $fail = fn (string $key, array $replace = []) => throw ValidationException::withMessages(['file' => __("attendance::attendance.device.{$key}", $replace)]);
        if ($file->getSize() > $maxKb * 1024) {
            $fail('file_size', ['max' => $maxKb]);
        }
        $content = (string) file_get_contents($file->getRealPath());
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            $fail('file_type');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $candidate) => substr_count($firstLine, $candidate))->first();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = null;
        $rows = [];
        $lineNo = 0;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $lineNo++;
            $cells = array_map(fn ($cell) => trim((string) $cell), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            if ($header === null) {
                $header = $cells;

                continue;
            }
            $rows[$lineNo] = $cells;
            if (count($rows) > $maxRows) {
                fclose($stream);
                $fail('too_many_rows', ['max' => $maxRows]);
            }
        }
        fclose($stream);
        if ($header === null || $rows === []) {
            $fail('empty');
        }

        return [$header, $rows];
    }

    /**
     * @param  list<string>  $header
     * @param  array<string, string>  $columns
     * @return array<string, int>
     */
    private function positions(array $header, array $columns): array
    {
        $lower = array_map(fn (string $name) => mb_strtolower($name), $header);
        $at = [];
        $errors = [];
        foreach ($columns as $key => $name) {
            $index = array_search(mb_strtolower(trim($name)), $lower, true);
            if ($index === false) {
                $errors["columns.{$key}"] = __('attendance::attendance.device.column_missing', ['column' => $name]);

                continue;
            }
            $at[$key] = $index;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $at;
    }
}
