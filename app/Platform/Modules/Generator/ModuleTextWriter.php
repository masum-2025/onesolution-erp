<?php

namespace App\Platform\Modules\Generator;

/**
 * A generated module's texts in a language: the PHP files the server reads
 * (module, permissions, rules, audit, messages) and the screens' JSON
 * (entity, field and choice labels). Names come from the definition;
 * sentences around them come from here, in English and Bangla.
 */
final class ModuleTextWriter
{
    private const SENTENCES = [
        'en' => [
            'view' => 'See :name',
            'manage' => 'Add, change and remove in :name',
            'created' => ':label made',
            'updated' => ':label changed',
            'deleted' => ':label removed',
            'not_company_unit' => 'Open a company or one of its units: a group keeps no records of its own.',
            'not_found' => 'That :label does not exist here. Reload the page.',
            'version_conflict' => 'Someone changed this a moment ago. It is fresh now; make your change again.',
            'no_currency' => 'Set the company\'s currency first (company settings).',
            'bad_link' => 'Choose one from this company (and this unit or one below it).',
            'in_use' => 'Other records still use this one. Change or remove them first.',
            'taken' => 'Another record already has this. Use a different one.',
            'general' => 'General',
        ],
        'bn' => [
            'view' => ':name দেখা',
            'manage' => ':name-এ যোগ, বদল ও মোছা',
            'created' => ':label তৈরি',
            'updated' => ':label বদল',
            'deleted' => ':label মোছা',
            'not_company_unit' => 'একটি কোম্পানি বা তার কোনো শাখা খুলুন: গ্রুপের নিজের কোনো রেকর্ড থাকে না।',
            'not_found' => 'এই :label এখানে নেই। পাতাটি আবার খুলুন।',
            'version_conflict' => 'একটু আগে অন্য কেউ এটি বদলেছেন। নতুন করে আনা হয়েছে; আবার বদলান।',
            'no_currency' => 'আগে কোম্পানির মুদ্রা ঠিক করুন (কোম্পানির সেটিংস)।',
            'bad_link' => 'এই কোম্পানির (আর এই শাখা বা তার নিচের) একটি বাছুন।',
            'in_use' => 'অন্য রেকর্ড এখনো এটা ব্যবহার করছে। আগে সেগুলো বদলান বা মুছুন।',
            'taken' => 'অন্য একটি রেকর্ডে এটা আগে থেকেই আছে। অন্য কিছু দিন।',
            'general' => 'সাধারণ',
        ],
    ];

    public function __construct(private ModuleDefinition $definition) {}

    /** @return array<string, string> file name (without .php) => contents */
    public function php(string $locale): array
    {
        $d = $this->definition->data;
        $s = self::SENTENCES[$locale];
        $say = fn (string $key, array $values) => strtr($s[$key], array_combine(array_map(fn ($name) => ":{$name}", array_keys($values)), array_values($values)));
        $name = $d['name'][$locale];

        $module = ['name' => $name, 'description' => $d['description'][$locale], 'menu' => $name];
        $audit = [];
        $errors = ['not_company_unit' => $s['not_company_unit'], 'version_conflict' => $s['version_conflict'], 'no_currency' => $s['no_currency'], 'bad_link' => $s['bad_link'], 'in_use' => $s['in_use']];
        foreach ($this->definition->entities() as $entity) {
            $module["menu_{$entity['plural_name']}"] = $entity['plural'][$locale];
            foreach (['created', 'updated', 'deleted'] as $what) {
                $audit["{$entity['name']}_{$what}"] = $say($what, ['label' => $entity['label'][$locale]]);
            }
            $errors["{$entity['name']}_not_found"] = $say('not_found', ['label' => $entity['label'][$locale]]);
        }
        $rules = [];
        foreach ($d['rules'] as $rule) {
            $rules[$rule['name']] = ['label' => $rule['label'][$locale], 'description' => $rule['description'][$locale]];
        }
        $rules['categories'] = ['general' => $s['general']];

        return [
            'module' => $this->file("{$name}: module texts (made by make:erp-module).", $module),
            'permissions' => $this->file('Permission labels, key = part after "'.$this->definition->key().'.".', ['view' => $say('view', ['name' => $name]), 'manage' => $say('manage', ['name' => $name])]),
            'rules' => $this->file('Rule labels, key = part after "'.$this->definition->key().'.".', $rules),
            'audit' => $this->file('Audit log labels ('.$this->definition->key().'.*).', $audit),
            'messages' => $this->file('Errors and checks.', ['errors' => $errors, 'validation' => ['taken' => $s['taken']]]),
        ];
    }

    /** The screens' texts: module, entity, field and choice labels. */
    public function json(string $locale): string
    {
        $d = $this->definition->data;
        $texts = ['module' => ['title' => $d['name'][$locale], 'description' => $d['description'][$locale]], 'entities' => [], 'fields' => [], 'options' => []];
        foreach ($this->definition->entities() as $entity) {
            $texts['entities'][$entity['name']] = ['label' => $entity['label'][$locale], 'plural' => $entity['plural'][$locale]];
            foreach ($entity['fields'] as $field) {
                $texts['fields'][$entity['name']][$field['name']] = $field['label'][$locale];
                if ($field['type'] === 'enum') {
                    foreach ($field['options'] as $value => $label) {
                        $texts['options'][$entity['name']][$field['name']][$value] = $label[$locale];
                    }
                }
            }
        }
        if ($texts['options'] === []) {
            unset($texts['options']);
        }

        return json_encode($texts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /** @param  array<string, mixed>  $values */
    private function file(string $comment, array $values): string
    {
        return "<?php\n\n// {$comment}\nreturn ".$this->export($values, 0).";\n";
    }

    /** @param  array<string, mixed>  $values */
    private function export(array $values, int $depth): string
    {
        $pad = str_repeat('    ', $depth + 1);
        $lines = [];
        foreach ($values as $key => $value) {
            $text = is_array($value) ? $this->export($value, $depth + 1) : var_export($value, true);
            $lines[] = $pad.var_export((string) $key, true).' => '.$text.',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }
}
