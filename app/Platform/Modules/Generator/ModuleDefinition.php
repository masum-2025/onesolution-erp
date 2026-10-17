<?php

namespace App\Platform\Modules\Generator;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A module described for the generator (make:erp-module): its key, names
 * in English and Bangla, the modules it needs, and its entities with their
 * fields, plus rules. Read from a PHP file returning an array, checked
 * strictly (every problem listed at once) and normalised:
 *
 *   field types   text, long_text, translatable, integer, quantity (stored
 *                 in thousandths, column NAME_milli), money (minor units of
 *                 the company's currency, column NAME_minor), date, boolean,
 *                 enum (options with labels), belongs_to (another entity of
 *                 the module, NAME ends in _id), employee (an HRM employee,
 *                 NAME ends in _id; needs hrm)
 *   per entity    title (a text or translatable field), search, filters
 *                 (enum or boolean fields), columns shown in the list,
 *                 unique fields
 *
 * Labels are given as ['en' => …, 'bn' => …]; both are required.
 */
final class ModuleDefinition
{
    public const TYPES = ['text', 'long_text', 'translatable', 'integer', 'quantity', 'money', 'date', 'boolean', 'enum', 'belongs_to', 'employee'];

    /** Columns every table has, never a field's name. */
    private const RESERVED = ['id', 'organization_id', 'unit_id', 'version', 'op_id', 'created_by', 'created_at', 'updated_at', 'currency', 'deleted_at'];

    /** @var list<string> */
    private array $problems = [];

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(public array $data) {}

    /**
     * @param  list<string>  $icons  Menu icons the app has.
     * @param  list<string>  $existingKeys  Module keys already installed.
     */
    public static function fromFile(string $path, array $icons, array $existingKeys): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("No definition file at {$path}.");
        }
        $data = require $path;
        if (! is_array($data)) {
            throw new InvalidArgumentException('The definition file must return an array.');
        }
        $definition = new self($data);
        $definition->check($icons, $existingKeys);
        if ($definition->problems !== []) {
            throw new InvalidArgumentException("The definition has problems:\n - ".implode("\n - ", $definition->problems));
        }
        $definition->normalise();

        return $definition;
    }

    public function key(): string
    {
        return $this->data['key'];
    }

    /** "fleet_management" -> "FleetManagement" */
    public function studly(): string
    {
        return Str::studly($this->data['key']);
    }

    /** "fleet_management" -> "fleet-management" (addresses). */
    public function slug(): string
    {
        return str_replace('_', '-', $this->data['key']);
    }

    /** @return array<string, array<string, mixed>> */
    public function entities(): array
    {
        return $this->data['entities'];
    }

    /** @param  list<string>  $icons  @param  list<string>  $existingKeys */
    private function check(array $icons, array $existingKeys): void
    {
        $d = $this->data;
        $key = $d['key'] ?? null;
        if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{1,30}$/', $key)) {
            $this->problems[] = 'key: lower-case letters, digits and _ (2-31), starting with a letter.';
        } elseif (in_array($key, $existingKeys, true)) {
            $this->problems[] = "key: a module \"{$key}\" already exists.";
        }
        $this->label($d['name'] ?? null, 'name');
        $this->label($d['description'] ?? null, 'description');
        foreach ((array) ($d['requires'] ?? []) as $required) {
            if (! is_string($required) || ! preg_match('/^[a-z][a-z0-9_]*$/', $required)) {
                $this->problems[] = 'requires: module keys only.';
            }
        }
        if (isset($d['icon']) && ! in_array($d['icon'], $icons, true)) {
            $this->problems[] = 'icon: one of '.implode(', ', $icons).'.';
        }
        $entities = $d['entities'] ?? null;
        if (! is_array($entities) || $entities === []) {
            $this->problems[] = 'entities: at least one.';

            return;
        }
        foreach ($entities as $name => $entity) {
            $this->entity((string) $name, (array) $entity, array_keys($entities), in_array('hrm', (array) ($d['requires'] ?? []), true));
        }
        foreach ((array) ($d['rules'] ?? []) as $index => $rule) {
            $where = "rules.{$index}";
            if (! is_string($rule['name'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]*$/', $rule['name'])) {
                $this->problems[] = "{$where}.name: snake_case.";
            }
            if (! in_array($rule['type'] ?? null, ['integer', 'boolean', 'string', 'enum'], true)) {
                $this->problems[] = "{$where}.type: integer, boolean, string or enum.";
            }
            if (($rule['type'] ?? null) === 'enum' && empty($rule['options'])) {
                $this->problems[] = "{$where}.options: an enum rule lists its options.";
            }
            if (! array_key_exists('default', $rule)) {
                $this->problems[] = "{$where}.default: required.";
            }
            $this->label($rule['label'] ?? null, "{$where}.label");
            $this->label($rule['description'] ?? null, "{$where}.description");
        }
    }

    /** @param  array<string, mixed>  $entity  @param  list<string|int>  $names */
    private function entity(string $name, array $entity, array $names, bool $hrm): void
    {
        $where = "entities.{$name}";
        if (! preg_match('/^[a-z][a-z0-9_]{1,30}$/', $name) || Str::plural($name) === $name) {
            $this->problems[] = "{$where}: a singular snake_case name (e.g. vehicle).";
        }
        $this->label($entity['label'] ?? null, "{$where}.label");
        $this->label($entity['plural'] ?? null, "{$where}.plural");
        $fields = $entity['fields'] ?? null;
        if (! is_array($fields) || $fields === []) {
            $this->problems[] = "{$where}.fields: at least one.";

            return;
        }
        $lower = [];
        foreach ($fields as $field => $spec) {
            $at = "{$where}.fields.{$field}";
            if (! preg_match('/^[a-z][a-z0-9_]{0,40}$/', (string) $field) || in_array($field, self::RESERVED, true)) {
                $this->problems[] = "{$at}: snake_case, not one of ".implode(', ', self::RESERVED).'.';
            }
            if (isset($lower[strtolower((string) $field)])) {
                $this->problems[] = "{$at}: two fields with the same name.";
            }
            $lower[strtolower((string) $field)] = true;
            $type = $spec['type'] ?? null;
            if (! in_array($type, self::TYPES, true)) {
                $this->problems[] = "{$at}.type: one of ".implode(', ', self::TYPES).'.';

                continue;
            }
            $this->label($spec['label'] ?? null, "{$at}.label");
            if ($type === 'enum') {
                if (empty($spec['options']) || ! is_array($spec['options'])) {
                    $this->problems[] = "{$at}.options: value => ['en' => …, 'bn' => …].";
                } else {
                    foreach ($spec['options'] as $value => $label) {
                        if (! preg_match('/^[a-z0-9_]{1,30}$/', (string) $value)) {
                            $this->problems[] = "{$at}.options.{$value}: snake_case values.";
                        }
                        $this->label($label, "{$at}.options.{$value}");
                    }
                }
            }
            if (in_array($type, ['belongs_to', 'employee'], true) && ! str_ends_with((string) $field, '_id')) {
                $this->problems[] = "{$at}: the name of a {$type} field ends in _id.";
            }
            if ($type === 'belongs_to' && (! in_array($spec['entity'] ?? null, $names, true) || ($spec['entity'] ?? null) === $name)) {
                $this->problems[] = "{$at}.entity: another entity of this module.";
            }
            if ($type === 'employee' && ! $hrm) {
                $this->problems[] = "{$at}: employee fields need 'hrm' in requires.";
            }
            if (! empty($spec['unique']) && ! in_array($type, ['text', 'integer'], true)) {
                $this->problems[] = "{$at}.unique: only text and integer fields.";
            }
            if (isset($spec['max']) && (! is_int($spec['max']) || $spec['max'] < 1 || $spec['max'] > 255)) {
                $this->problems[] = "{$at}.max: 1-255.";
            }
        }
        $title = $entity['title'] ?? array_key_first($fields);
        if (! in_array($fields[$title]['type'] ?? null, ['text', 'translatable'], true)) {
            $this->problems[] = "{$where}.title: a text or translatable field.";
        }
        foreach ((array) ($entity['filters'] ?? []) as $filter) {
            if (! in_array($fields[$filter]['type'] ?? null, ['enum', 'boolean', 'belongs_to', 'employee'], true)) {
                $this->problems[] = "{$where}.filters.{$filter}: an enum, boolean or link field.";
            }
        }
        foreach ([...(array) ($entity['search'] ?? []), ...(array) ($entity['columns'] ?? [])] as $column) {
            if (! isset($fields[$column])) {
                $this->problems[] = "{$where}: \"{$column}\" is not a field.";
            }
        }
        foreach ((array) ($entity['search'] ?? []) as $column) {
            if (! in_array($fields[$column]['type'] ?? null, ['text', 'long_text'], true)) {
                $this->problems[] = "{$where}.search.{$column}: text fields only.";
            }
        }
    }

    private function label(mixed $label, string $where): void
    {
        if (! is_array($label) || ! is_string($label['en'] ?? null) || trim($label['en']) === '' || ! is_string($label['bn'] ?? null) || trim($label['bn']) === '') {
            $this->problems[] = "{$where}: ['en' => …, 'bn' => …], both written.";
        }
    }

    /** Defaults filled in; each field gains its API/column name. */
    private function normalise(): void
    {
        $this->data['requires'] = array_values((array) ($this->data['requires'] ?? []));
        $this->data['sectors'] = $this->data['sectors'] ?? ['*'];
        $this->data['plans'] = $this->data['plans'] ?? ['*'];
        $this->data['icon'] = $this->data['icon'] ?? 'package';
        $this->data['rules'] = array_values((array) ($this->data['rules'] ?? []));
        foreach ($this->data['entities'] as $name => &$entity) {
            $entity['name'] = $name;
            $entity['plural_name'] = Str::plural($name);
            $entity['class'] = Str::studly($name);
            $entity['plural_class'] = Str::studly($entity['plural_name']);
            $entity['table'] = $this->data['key'].'_'.$entity['plural_name'];
            $entity['title'] = $entity['title'] ?? array_key_first($entity['fields']);
            $entity['search'] = array_values((array) ($entity['search'] ?? []));
            $entity['filters'] = array_values((array) ($entity['filters'] ?? []));
            $entity['columns'] = array_values((array) ($entity['columns'] ?? array_slice(array_keys($entity['fields']), 0, 4)));
            foreach ($entity['fields'] as $field => &$spec) {
                $spec['name'] = $field;
                $spec['column'] = match ($spec['type']) {
                    'quantity' => "{$field}_milli",
                    'money' => "{$field}_minor",
                    default => $field,
                };
                $spec['required'] = (bool) ($spec['required'] ?? false);
                $spec['unique'] = (bool) ($spec['unique'] ?? false);
                $spec['max'] = $spec['max'] ?? ($spec['type'] === 'text' ? 150 : null);
            }
            unset($spec);
        }
        unset($entity);
    }
}
