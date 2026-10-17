<?php

namespace App\Platform\Modules\Generator;

/**
 * A generated module's Pest tests: per entity the happy path (made once per
 * op id, listed, searched, read, changed with its version, a stale version
 * refused, removed), unique values, links in use; then validation, the
 * permission, the module off and another company's records. Sample values
 * are made from each field's type.
 */
final class ModuleTestWriter
{
    public function __construct(private ModuleDefinition $definition) {}

    public function test(): string
    {
        $key = $this->definition->key();
        $studly = $this->definition->studly();
        $slug = $this->definition->slug();
        $entities = $this->ordered();
        $needsEmployee = $this->hasType('employee');
        $modules = implode(', ', array_map(fn ($module) => "'{$module}'", [...$this->definition->data['requires'], $key]));
        $payload = lcfirst($studly).'Payload';

        $records = '';
        foreach ($entities as $entity) {
            $path = str_replace('_', '-', $entity['plural_name']);
            $records .= "\n    \$this->records['{$entity['name']}'] = \$this->asToken(\$this->token)->postJson((\$this->api)('{$path}'), {$payload}(\$this, '{$entity['name']}', 1))->assertCreated()->json('data');";
        }
        $employee = $needsEmployee ? "\n    \$this->employee = hireVia(\$this, \$this->token, \$this->w->c1)->assertCreated()->json('data');" : '';

        $cases = '';
        foreach ($entities as $entity) {
            $cases .= $this->entityTest($entity, $payload);
        }
        $required = [];
        foreach ($entities as $entity) {
            foreach ($entity['fields'] as $field) {
                if ($field['required']) {
                    $required[$entity['name']][] = $field['type'] === 'translatable' ? "{$field['column']}" : $field['column'];
                }
            }
        }
        $first = $entities[0];
        $firstPath = str_replace('_', '-', $first['plural_name']);
        $validation = isset($required[$first['name']])
            ? "\$this->asToken(\$this->token)->postJson((\$this->api)('{$firstPath}'), [])->assertUnprocessable()->assertJsonValidationErrors(".var_export($required[$first['name']], true).');'
            : "\$this->asToken(\$this->token)->postJson((\$this->api)('{$firstPath}'), [...{$payload}(\$this, '{$first['name']}', 5), 'organization_id' => 'x'])->assertUnprocessable();";
        $functions = $this->payloadFunction($payload);

        return <<<PHP
<?php

use Carbon\CarbonImmutable;

/*
 * {$this->definition->data['name']['en']} (made by make:erp-module): each entity made once per tap, listed,
 * searched, read, changed with its version and removed; validation, the permission, the module off,
 * and another company's records. Grow these tests with the module.
 */

beforeEach(function () {
    \$this->travelTo(CarbonImmutable::parse('2026-11-02 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    \$this->w = tenancyWorld();
    // A plan with every module (a new module is in no listed plan until someone adds it).
    setPlan(\$this->w->g1, 'business');
    foreach ([{$modules}] as \$module) {
        toggles()->enable(\$this->w->g1, \$module, 'Test setup');
    }
    \$this->owner = createMember(\$this->w->c1);
    \$this->token = orgToken(\$this->owner, \$this->w->c1);
    \$this->viewer = orgToken(staffWithRoles(\$this->w->c1, makeRole(\$this->w->c1, ['{$key}.view'], 'Viewer')), \$this->w->c1);
    \$this->api = fn (string \$path, \$unit = null) => '/api/organizations/'.(\$unit ?? \$this->w->c1)->id."/{$slug}/{\$path}";{$employee}
    \$this->records = [];{$records}
});
{$cases}
it('checks what is sent, keeps writing to managers, and answers 403 with the module off', function () {
    {$validation}
    \$this->asToken(\$this->viewer)->postJson((\$this->api)('{$firstPath}'), {$payload}(\$this, '{$first['name']}', 6))->assertForbidden();
    \$this->asToken(\$this->viewer)->getJson((\$this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', false);
    \$this->asToken(\$this->token)->getJson((\$this->api)('setup'))->assertOk()->assertJsonPath('data.can.manage', true);

    toggles()->disable(\$this->w->c1, '{$key}', 'Test');
    \$this->asToken(\$this->token)->getJson((\$this->api)('{$firstPath}'))->assertForbidden();
});

it('never shows or changes another company\'s records', function () {
    \$record = \$this->records['{$first['name']}'];
    \$other = orgToken(createMember(\$this->w->c2), \$this->w->c2);
    \$this->asToken(\$other)->getJson((\$this->api)("{$firstPath}/{\$record['id']}", \$this->w->c2))->assertNotFound();
    \$this->asToken(\$other)->patchJson((\$this->api)("{$firstPath}/{\$record['id']}", \$this->w->c2), ['base_version' => 1])->assertNotFound();
    \$this->asToken(\$other)->getJson((\$this->api)("{$firstPath}/{\$record['id']}"))->assertNotFound();
});
{$functions}
PHP;
    }

    /** @param  array<string, mixed>  $entity */
    private function entityTest(array $entity, string $payload): string
    {
        $path = str_replace('_', '-', $entity['plural_name']);
        $title = $entity['fields'][$entity['title']];
        $changed = $title['type'] === 'translatable' ? "['en' => 'Changed', 'bn' => 'বদল']" : "'Changed'";
        $changedCheck = $title['type'] === 'translatable' ? "\$changed['{$title['column']}']['en']" : "\$changed['{$title['column']}']";
        $search = '';
        if ($entity['search'] !== []) {
            $column = $entity['fields'][$entity['search'][0]]['column'];
            $search = "\n    \$found = \$this->asToken(\$this->viewer)->getJson((\$this->api)('{$path}?q='.urlencode(mb_substr((string) \$made['{$column}'], 0, 6))))->assertOk()->json('data');\n    expect(collect(\$found)->pluck('id'))->toContain(\$made['id']);";
        }
        $unique = '';
        foreach ($entity['fields'] as $field) {
            if ($field['unique']) {
                $unique .= "\n    \$this->asToken(\$this->token)->postJson((\$this->api)('{$path}'), {$payload}(\$this, '{$entity['name']}', 2))->assertUnprocessable()->assertJsonValidationErrors('{$field['column']}');";
            }
        }
        $users = [];
        foreach ($this->definition->entities() as $other) {
            foreach ($other['fields'] as $field) {
                if ($field['type'] === 'belongs_to' && $field['entity'] === $entity['name']) {
                    $users[] = $other;
                }
            }
        }
        $removal = $users === []
            ? "\n    \$this->asToken(\$this->token)->deleteJson((\$this->api)(\"{$path}/{\$made['id']}?base_version=2\"))->assertNoContent();\n    \$this->asToken(\$this->token)->getJson((\$this->api)(\"{$path}/{\$made['id']}\"))->assertNotFound();"
            : "\n    // Records of other entities link to the first one: it stays.\n    \$first = \$this->records['{$entity['name']}'];\n    \$this->asToken(\$this->token)->deleteJson((\$this->api)(\"{$path}/{\$first['id']}?base_version=1\"))->assertStatus(409)->assertJsonPath('code', 'in_use');\n    \$this->asToken(\$this->token)->deleteJson((\$this->api)(\"{$path}/{\$made['id']}?base_version=2\"))->assertNoContent();";
        $label = $entity['plural']['en'];

        return <<<PHP

it('keeps {$label}: made once per tap, listed, read, changed with their version, removed', function () {
    \$made = \$this->asToken(\$this->token)->postJson((\$this->api)('{$path}'), [...{$payload}(\$this, '{$entity['name']}', 2), 'op_id' => 'tap-{$entity['name']}'])->assertCreated()->json('data');
    \$this->asToken(\$this->token)->postJson((\$this->api)('{$path}'), [...{$payload}(\$this, '{$entity['name']}', 2), 'op_id' => 'tap-{$entity['name']}'])->assertOk()->assertJsonPath('data.id', \$made['id']);{$unique}
    expect(collect(\$this->asToken(\$this->viewer)->getJson((\$this->api)('{$path}'))->assertOk()->json('data'))->pluck('id'))->toContain(\$made['id']);{$search}
    \$this->asToken(\$this->viewer)->getJson((\$this->api)("{$path}/{\$made['id']}"))->assertOk()->assertJsonPath('data.version', 1);

    \$changed = \$this->asToken(\$this->token)->patchJson((\$this->api)("{$path}/{\$made['id']}"), ['{$title['column']}' => {$changed}, 'base_version' => 1])->assertOk()->json('data');
    expect({$changedCheck})->toBe('Changed')->and(\$changed['version'])->toBe(2);
    \$this->asToken(\$this->token)->patchJson((\$this->api)("{$path}/{\$made['id']}"), ['{$title['column']}' => {$changed}, 'base_version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    \$this->asToken(\$this->viewer)->deleteJson((\$this->api)("{$path}/{\$made['id']}?base_version=2"))->assertForbidden();{$removal}
});

PHP;
    }

    private function payloadFunction(string $name): string
    {
        $cases = '';
        foreach ($this->definition->entities() as $entity) {
            $values = [];
            foreach ($entity['fields'] as $field) {
                $column = $field['column'];
                $max = $field['max'] ?? 150;
                $values[] = "'{$column}' => ".match ($field['type']) {
                    'text' => "mb_substr('".str_replace("'", '', $entity['label']['en'])." {$field['name']} '.\$n, 0, {$max})",
                    'long_text' => "'Notes '.\$n",
                    'translatable' => "['en' => mb_substr('".str_replace("'", '', $entity['label']['en'])." '.\$n, 0, {$max}), 'bn' => 'নমুনা '.\$n]",
                    'integer' => '40 + $n',
                    'quantity' => '2500 * $n',
                    'money' => '125050 * $n',
                    'date' => "'2026-10-'.str_pad((string) min(28, \$n + 10), 2, '0', STR_PAD_LEFT)",
                    'boolean' => 'true',
                    'enum' => var_export((string) array_key_first($field['options']), true),
                    'belongs_to' => "\$test->records['{$field['entity']}']['id']",
                    'employee' => "\$test->employee['id']",
                };
            }
            $body = implode(",\n            ", $values);
            $cases .= "\n        '{$entity['name']}' => [\n            {$body},\n        ],";
        }

        return <<<PHP

/** A full, valid record of an entity; \$n makes text values differ. */
function {$name}(\$test, string \$entity, int \$n): array
{
    return match (\$entity) {{$cases}
    };
}

PHP;
    }

    /**
     * Entities in an order where each comes after the ones it links to.
     *
     * @return list<array<string, mixed>>
     */
    private function ordered(): array
    {
        $entities = $this->definition->entities();
        $done = [];
        $order = [];
        while (count($order) < count($entities)) {
            $before = count($order);
            foreach ($entities as $name => $entity) {
                if (isset($done[$name])) {
                    continue;
                }
                $needs = array_filter(array_map(fn ($field) => $field['type'] === 'belongs_to' ? $field['entity'] : null, $entity['fields']));
                if (array_diff($needs, array_keys($done)) === []) {
                    $done[$name] = true;
                    $order[] = $entity;
                }
            }
            if (count($order) === $before) {
                throw new \InvalidArgumentException('Entities link to each other in a circle; one link must be optional and made later.');
            }
        }

        return $order;
    }

    private function hasType(string $type): bool
    {
        foreach ($this->definition->entities() as $entity) {
            foreach ($entity['fields'] as $field) {
                if ($field['type'] === $type) {
                    return true;
                }
            }
        }

        return false;
    }
}
