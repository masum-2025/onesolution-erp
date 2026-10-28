<?php

namespace Modules\Compliance\Services;

use App\Models\User;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Models\Audit;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * The register from an Excel (.xlsx) or CSV file: checked row by row first
 * (nothing stored), then imported with the same rules as by hand. A row
 * names its factory (company or branch) and service by name or code, in
 * either language; a provider not in the list yet is added. A line already
 * in the register gets the file's dates and details, with the file named
 * as the reason. The file itself is never kept.
 */
class Importer
{
    public const COLUMNS = ['factory', 'service', 'provider', 'audit_type', 'last_audit_on', 'expires_on', 'result', 'certificate_no'];

    public const REQUIRED = ['factory', 'service'];

    public function __construct(
        private Place $place,
        private Lists $lists,
        private Register $register,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function maxRows(Organization $unit): int
    {
        return (int) $this->rules->get('compliance.import_max_rows', $this->contexts->forOrganization($unit));
    }

    /**
     * Check every row; nothing is stored.
     *
     * @return array{total: int, valid: int, rows: list<array{row_no: int, factory: string, service: string, action: string, new_provider: bool, errors: array<string, string>}>}
     */
    public function check(Organization $unit, UploadedFile $file): array
    {
        $plan = $this->plan($unit, $file);

        return [
            'total' => count($plan),
            'valid' => count(array_filter($plan, fn (array $row) => $row['errors'] === [])),
            'rows' => array_map(fn (array $row) => array_intersect_key($row, array_flip(['row_no', 'factory', 'service', 'action', 'new_provider', 'errors'])), $plan),
        ];
    }

    /**
     * Import the valid rows (all must be valid unless skipping the others).
     *
     * @return array{added: int, updated: int, unchanged: int, skipped: int}
     */
    public function import(Organization $unit, UploadedFile $file, bool $skipInvalid, User $actor): array
    {
        $plan = $this->plan($unit, $file);
        $invalid = count(array_filter($plan, fn (array $row) => $row['errors'] !== []));
        if ($invalid > 0 && ! $skipInvalid) {
            throw ComplianceException::importHasErrors($invalid);
        }
        $reason = __('compliance::compliance.import.reason', ['file' => mb_substr($file->getClientOriginalName(), 0, 120)]);
        $done = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => $invalid];

        $this->place->transaction($unit, function () use ($plan, $reason, $actor, &$done) {
            $providers = [];
            foreach ($plan as $row) {
                if ($row['errors'] !== []) {
                    continue;
                }
                $factory = $row['_factory'];
                $data = $row['_data'];
                if ($row['_provider_name'] !== null) {
                    $owner = $this->listOwner($factory);
                    $key = $owner->getKey().'|'.mb_strtolower($row['_provider_name']);
                    $providers[$key] ??= $this->lists->save($owner, 'providers', null, ['name' => $row['_provider_name'], 'is_active' => true], $actor)->getKey();
                    $data['provider_id'] = $providers[$key];
                }
                if ($row['_audit'] === null) {
                    $this->register->add($factory, [...$data, 'reason' => $reason], true, $actor, 'imported');
                    $done['added']++;

                    continue;
                }
                $audit = $row['_audit'];
                $changed = false;
                $details = array_intersect_key($data, array_flip(['provider_id', 'audit_type_id', 'result', 'certificate_no']));
                $after = $this->register->update($factory, $audit, $audit->version, $details, $actor);
                $changed = $after->version !== $audit->version;
                if (array_key_exists('last_audit_on', $data) || array_key_exists('expires_on', $data)) {
                    $dated = $this->register->setDates($factory, $after, $after->version, $data['last_audit_on'] ?? $after->last_audit_on?->toDateString(),
                        $data['expires_on'] ?? $after->expires_on?->toDateString(), $reason, $actor, 'imported');
                    $changed = $changed || $dated->version !== $after->version;
                }
                $done[$changed ? 'updated' : 'unchanged']++;
            }
        });

        return $done;
    }

    /**
     * Each row read, matched and checked.
     *
     * @return list<array<string, mixed>>
     */
    private function plan(Organization $unit, UploadedFile $file): array
    {
        [$header, $rows] = $this->read($file, $this->maxRows($unit));
        $missing = array_diff(self::REQUIRED, $header);
        if ($missing !== []) {
            throw ComplianceException::importColumns(implode(', ', $missing));
        }
        $unknown = array_diff($header, self::COLUMNS, ['']);
        if ($unknown !== []) {
            throw ComplianceException::importUnknownColumns(implode(', ', array_slice($unknown, 0, 10)));
        }

        $factories = $this->byName($this->place->factories($unit), fn (Organization $factory) => $factory->texts('name'));
        $existing = $this->register->lines($unit)->keyBy(fn (Audit $audit) => $audit->organization_id.'|'.$audit->service_id);
        $lists = [];
        $listFor = function (Organization $factory, string $kind) use (&$lists) {
            return $lists[$factory->getKey().$kind] ??= $this->lists->of($factory, $kind);
        };
        $seen = [];
        $plan = [];

        foreach ($rows as $index => $cells) {
            // A column the file does not have is empty (never position 0 by accident).
            $raw = fn (string $column) => ($position = array_search($column, $header, true)) === false ? null : ($cells[$position] ?? null);
            $cell = fn (string $column) => $this->text($raw($column));
            $errors = [];
            foreach ($header as $position => $column) {
                $value = $cells[$position] ?? null;
                if (is_string($value) && $value !== '' && in_array($value[0], ['=', '@'], true)) {
                    $errors[$column] = __('compliance::compliance.import.formula');
                }
            }
            $factoryName = $cell('factory') ?? '';
            $serviceName = $cell('service') ?? '';
            $factory = $factories[mb_strtolower($factoryName)] ?? null;
            if ($factory === null) {
                $errors['factory'] = __($factoryName === '' ? 'compliance::compliance.import.required' : 'compliance::compliance.import.factory_unknown');
            }
            $data = [];
            $providerName = null;
            $audit = null;
            if ($factory !== null) {
                $service = $this->match($listFor($factory, 'services'), $serviceName);
                if ($service === null) {
                    $errors['service'] = __($serviceName === '' ? 'compliance::compliance.import.required' : 'compliance::compliance.import.service_unknown');
                } else {
                    $data['service_id'] = $service->getKey();
                    $key = $factory->getKey().'|'.$service->getKey();
                    if (isset($seen[$key])) {
                        $errors['service'] = __('compliance::compliance.import.twice', ['row' => $seen[$key]]);
                    }
                    $seen[$key] = $index + 2;
                    $audit = $existing[$key] ?? null;
                }
                if (($name = $cell('provider')) !== null) {
                    $provider = $listFor($factory, 'providers')->first(fn (Model $item) => mb_strtolower($item->name) === mb_strtolower($name));
                    $provider === null ? $providerName = mb_substr($name, 0, 150) : $data['provider_id'] = $provider->getKey();
                }
                if (($type = $cell('audit_type')) !== null) {
                    $found = $this->match($listFor($factory, 'types'), $type);
                    $found === null ? $errors['audit_type'] = __('compliance::compliance.import.type_unknown') : $data['audit_type_id'] = $found->getKey();
                }
            }
            foreach (['last_audit_on', 'expires_on'] as $column) {
                if ($cell($column) !== null) {
                    $date = $this->date($raw($column));
                    $date === null ? $errors[$column] = __('compliance::compliance.import.bad_date') : $data[$column] = $date;
                }
            }
            if (isset($data['last_audit_on'], $data['expires_on']) && $data['expires_on'] < $data['last_audit_on']) {
                $errors['expires_on'] = __('compliance::compliance.errors.dates_order');
            }
            foreach (['result' => 60, 'certificate_no' => 100] as $column => $max) {
                if (($value = $cell($column)) !== null) {
                    mb_strlen($value) > $max ? $errors[$column] = __('compliance::compliance.import.too_long', ['max' => $max]) : $data[$column] = $value;
                }
            }

            $plan[] = [
                'row_no' => $index + 2,
                'factory' => $factory?->displayName() ?? $factoryName,
                'service' => $serviceName,
                'action' => $audit === null ? 'add' : 'update',
                'new_provider' => $providerName !== null,
                'errors' => $errors,
                '_factory' => $factory,
                '_audit' => $audit,
                '_data' => $data,
                '_provider_name' => $providerName,
            ];
        }

        return $plan;
    }

    /**
     * The header (lower-case column keys) and the rows (cell values), empty rows left out.
     *
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function read(UploadedFile $file, int $maxRows): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $reader = $extension === 'xlsx' ? new XlsxReader : new CsvReader;
        $header = null;
        $rows = [];
        try {
            $reader->open($file->getRealPath());
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    if ($header === null) {
                        $header = array_map(fn ($value) => strtolower(trim(str_replace("\u{FEFF}", '', (string) $value))), $values);

                        continue;
                    }
                    if (array_filter($values, fn ($value) => $value !== null && trim((string) ($value instanceof DateTimeInterface ? 'x' : $value)) !== '') === []) {
                        continue;
                    }
                    if (count($rows) >= $maxRows) {
                        throw ComplianceException::importTooManyRows($maxRows);
                    }
                    $rows[] = $values;
                }
                // The first sheet only.
                break;
            }
        } catch (ComplianceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ComplianceException::importUnreadable();
        } finally {
            $reader->close();
        }
        if ($header === null || $rows === []) {
            throw ComplianceException::importEmpty();
        }

        return [$header, $rows];
    }

    /** Text of a cell, trimmed; null when empty. */
    private function text(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** A day from a date cell or text: 2026-10-01, 01/10/2026, 01-10-2026, 01.10.2026, 1 Oct 2026. */
    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        // A date cell without a date format reads as Excel's day number (days since 30 December 1899).
        if ((is_int($value) || is_float($value)) && $value >= 20000 && $value <= 80000) {
            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addDays((int) floor($value))->toDateString();
        }
        $text = trim((string) $value);
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!j M Y', '!d M Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $text, 'UTC');
            } catch (Throwable) {
                // Carbon refuses text that does not fit the format: try the next one.
                continue;
            }
            if ($date !== false && $date !== null && $date->format(ltrim($format, '!')) === $text) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /**
     * Items found by lower-case name in any language.
     *
     * @param  Collection<int, Organization>  $items
     * @return array<string, Organization>
     */
    private function byName(Collection $items, callable $names): array
    {
        $map = [];
        foreach ($items as $item) {
            foreach (array_filter($names($item)) as $name) {
                $map[mb_strtolower(trim((string) $name))] = $item;
            }
        }

        return $map;
    }

    /** A service or kind by its code or its name in any language. */
    private function match(Collection $items, string $text): ?Model
    {
        if ($text === '') {
            return null;
        }
        $wanted = mb_strtolower($text);

        return $items->first(fn (Model $item) => mb_strtolower((string) $item->code) === $wanted
            || in_array($wanted, array_map(fn ($name) => mb_strtolower(trim((string) $name)), array_filter($item->texts('name'))), true));
    }

    /** New providers are kept by the factory's company: imports run at a company, which writes only its own data. */
    private function listOwner(Organization $factory): Organization
    {
        return $this->place->companyOf($factory);
    }
}
