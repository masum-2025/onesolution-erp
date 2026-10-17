<?php

namespace App\Platform\Modules\Generator;

use Carbon\CarbonImmutable;

/**
 * Writes a module from a checked ModuleDefinition: every file as text,
 * keyed by its path from the project root. Nothing touches the disk here
 * (the command shows them first, refuses to overwrite, then writes).
 *
 * The module follows the platform's rules: tenant tables with ULIDs, the
 * organization and unit of each record, versions for changes and an op id
 * for making; money in minor units of the company's currency, quantities
 * in thousandths; permissions view/manage checked at the unit; the module
 * switched off gives 403; audit for every change; texts in English and
 * Bangla; a list screen per entity from the shared generated components;
 * and tests for the happy path, validation, permission, module off and
 * isolation between companies.
 */
final class ModuleWriter
{
    private string $key;

    private string $studly;

    private string $slug;

    public function __construct(private ModuleDefinition $definition, private ?CarbonImmutable $now = null)
    {
        $this->key = $definition->key();
        $this->studly = $definition->studly();
        $this->slug = $definition->slug();
        $this->now ??= CarbonImmutable::now();
    }

    /** @return array<string, string> path => contents */
    public function files(): array
    {
        $root = "Modules/{$this->studly}";
        $files = [
            "{$root}/module.json" => $this->moduleJson(),
            "{$root}/composer.json" => $this->composerJson(),
            "{$root}/manifest.php" => $this->manifest(),
            "{$root}/app/Providers/{$this->studly}ServiceProvider.php" => $this->provider(),
            "{$root}/app/Exceptions/{$this->studly}Exception.php" => $this->exception(),
            "{$root}/app/Services/{$this->studly}Store.php" => $this->store(),
            "{$root}/app/Http/{$this->studly}Presenter.php" => $this->presenter(),
            "{$root}/app/Http/Controllers/Concerns/Finds{$this->studly}.php" => $this->finds(),
            "{$root}/app/Http/Controllers/SetupController.php" => $this->setupController(),
            "{$root}/routes/api.php" => $this->routes(),
            "{$root}/database/migrations/tenant/{$this->now->format('Y_m_d_His')}_create_{$this->key}_tables.php" => $this->migration(),
            "{$root}/resources/js/schema.js" => $this->schemaJs(),
            "{$root}/resources/js/routes.js" => $this->routesJs(),
            "tests/Feature/{$this->studly}/{$this->studly}Test.php" => (new ModuleTestWriter($this->definition))->test(),
        ];
        foreach ($this->definition->entities() as $entity) {
            $files["{$root}/app/Models/{$entity['class']}.php"] = $this->model($entity);
            $files["{$root}/app/Services/{$entity['plural_class']}.php"] = $this->service($entity);
            $files["{$root}/app/Http/Requests/{$entity['class']}Request.php"] = $this->request($entity);
            $files["{$root}/app/Http/Controllers/{$entity['class']}Controller.php"] = $this->controller($entity);
        }
        foreach (['en', 'bn'] as $locale) {
            foreach ((new ModuleTextWriter($this->definition))->php($locale) as $name => $text) {
                $files["{$root}/lang/{$locale}/{$name}.php"] = $text;
            }
            $files["{$root}/resources/js/locales/{$locale}/{$this->key}.json"] = (new ModuleTextWriter($this->definition))->json($locale);
        }

        return $files;
    }

    /** The section added at the end of README.md. */
    public function readme(): string
    {
        $entities = implode(', ', array_map(fn (array $entity) => "`{$entity['table']}`", $this->definition->entities()));
        $name = $this->definition->data['name']['en'];

        return <<<MD

## {$name} (module `{$this->key}`, made by make:erp-module)

{$this->definition->data['description']['en']}

- Tables {$entities}; API under `/api/organizations/{organization}/{$this->slug}/…` (list with `q`, filters and pages, show,
  make with `op_id`, change and remove with `base_version`), `GET …/{$this->slug}/setup` for the screens.
- Permissions `{$this->key}.view` (read) and `{$this->key}.manage` (write) at the unit; the module off answers 403.
- Screens from the shared generated list (`resources/js/components/generated`) and `resources/js/schema.js`.
- After deploy: `composer dump-autoload`, migrate, `rules:sync`, `access:sync`.
- A new sector, country or partner needs no code change: labels are texts, numbers are rules.

MD;
    }

    private function moduleJson(): string
    {
        return json_encode([
            'name' => $this->studly, 'alias' => $this->key, 'description' => $this->definition->data['description']['en'], 'keywords' => [], 'priority' => 0,
            'providers' => ["Modules\\{$this->studly}\\Providers\\{$this->studly}ServiceProvider"], 'files' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function composerJson(): string
    {
        return json_encode([
            'name' => 'onesolutions/'.$this->slug, 'description' => $this->definition->data['description']['en'],
            'autoload' => ['psr-4' => ["Modules\\{$this->studly}\\" => 'app/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function manifest(): string
    {
        $d = $this->definition->data;
        $list = fn (array $items) => '['.implode(', ', array_map(fn ($item) => var_export($item, true), $items)).']';
        $rules = '';
        foreach ($d['rules'] as $order => $rule) {
            $schema = match ($rule['type']) {
                'enum' => "\n            'schema' => ['enum' => ".$list(array_keys($rule['options'])).'],',
                'integer' => isset($rule['min']) || isset($rule['max']) ? "\n            'schema' => [".implode(', ', array_filter([isset($rule['min']) ? "'minimum' => {$rule['min']}" : null, isset($rule['max']) ? "'maximum' => {$rule['max']}" : null])).'],' : '',
                default => '',
            };
            $default = var_export($rule['default'], true);
            $sort = ($order + 1) * 10;
            $rules .= <<<PHP

        [
            'key' => '{$this->key}.{$rule['name']}',
            'type' => '{$rule['type']}',{$schema}
            'default' => {$default},
            'label' => '{$this->key}::rules.{$rule['name']}.label',
            'description' => '{$this->key}::rules.{$rule['name']}.description',
            'overridable_levels' => ['platform', 'partner', 'group', 'company', 'branch'],
            'category' => 'general',
            'sort_order' => {$sort},
        ],
PHP;
        }
        $children = '';
        foreach ($this->definition->entities() as $entity) {
            $path = str_replace('_', '-', $entity['plural_name']);
            $children .= "\n                ['key' => '{$entity['plural_name']}', 'label' => '{$this->key}::module.menu_{$entity['plural_name']}', 'route' => '/{$this->slug}/{$path}', 'permission' => '{$this->key}.view'],";
        }
        $first = str_replace('_', '-', array_values($this->definition->entities())[0]['plural_name']);
        $requires = $list($d['requires']);
        $sectors = $list($d['sectors']);
        $plans = $list($d['plans']);

        return <<<PHP
<?php

/*
| Module manifest: read by App\Platform\Modules\ModuleRegistry.
| Made by make:erp-module; business numbers live in rules, never here.
*/

return [
    'key' => '{$this->key}',
    'name' => '{$this->key}::module.name',
    'description' => '{$this->key}::module.description',
    'version' => '1.0.0',
    'category' => 'business',
    'requires' => {$requires},
    'sectors' => {$sectors},
    'plans' => {$plans},
    'permissions' => ['{$this->key}.view', '{$this->key}.manage'],
    'rules' => [{$rules}
    ],
    'menu' => [
        [
            'key' => '{$this->key}',
            'label' => '{$this->key}::module.menu',
            'route' => '/{$this->slug}/{$first}',
            'icon' => '{$d['icon']}',
            'order' => 90,
            'children' => [{$children}
            ],
        ],
    ],
    'events' => [],
    'is_core' => false,
    'requires_consent' => false,
    'dashboard' => ['widgets' => []],
    'settings' => ['pages' => []],
];

PHP;
    }

    private function provider(): string
    {
        return <<<PHP
<?php

namespace Modules\\{$this->studly}\\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * {$this->definition->data['name']['en']}: {$this->definition->data['description']['en']}
 */
class {$this->studly}ServiceProvider extends ServiceProvider
{
    /** Writes a person may send a minute. */
    private const WRITES_PER_MINUTE = 60;

    public function boot(): void
    {
        \$root = dirname(__DIR__, 2);
        \$this->loadTranslationsFrom(\$root.'/lang', '{$this->key}');

        RateLimiter::for('{$this->slug}-writes', fn (Request \$request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by('{$this->slug}-writes:'.(\$request->user()?->getKey() ?? \$request->ip())));

        if (! \$this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(\$root.'/routes/api.php');
        }
    }
}

PHP;
    }

    private function exception(): string
    {
        return <<<PHP
<?php

namespace Modules\\{$this->studly}\\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * {$this->definition->data['name']['en']} errors: a translated message ({$this->key}::messages.errors.*) that says what to do next, and a stable code.
 */
class {$this->studly}Exception extends TenancyException
{
    protected function translationKey(): string
    {
        return '{$this->key}::messages.errors.'.\$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function notFound(string \$what): self
    {
        return new self("{\$what}_not_found", 404);
    }

    public static function versionConflict(array \$current): self
    {
        return new self('version_conflict', 409, extra: ['current' => \$current]);
    }

    public static function noCurrency(): self
    {
        return new self('no_currency', 422);
    }

    /** A link to something that is not the company's (or not this unit's), named by its field. */
    public static function badLink(string \$field): self
    {
        return new self('bad_link', 422, [], ['field' => \$field]);
    }

    /** Other records still use this one. */
    public static function inUse(): self
    {
        return new self('in_use', 409);
    }
}

PHP;
    }

    private function store(): string
    {
        return <<<PHP
<?php

namespace Modules\\{$this->studly}\\Services;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\\{$this->studly}\\Exceptions\\{$this->studly}Exception;

/**
 * Where the module's records are kept: a company (or personal workspace) and
 * its units, in the client's database. Queries name the company, so they
 * work with or without a tenant context. Money is in the company's currency.
 */
class {$this->studly}Store
{
    public function __construct(private TenantDatabases \$databases, private OrganizationSettingsResolver \$settings) {}

    public function companyOf(Organization \$unit): Organization
    {
        if (in_array(\$unit->type, [OrganizationType::Company, OrganizationType::Personal], true)) {
            return \$unit;
        }
        if (\$unit->type === OrganizationType::Group) {
            throw {$this->studly}Exception::notCompanyUnit();
        }

        return Organization::query()->whereKey(\$unit->ancestorIds())
            ->whereIn('type', [OrganizationType::Company->value, OrganizationType::Personal->value])
            ->orderByDesc('depth')->first() ?? throw {$this->studly}Exception::notCompanyUnit();
    }

    /** @return list<string> The unit and every unit below it. */
    public function subtreeIds(Organization \$unit): array
    {
        return Organization::query()->subtreeOf(\$unit)->pluck('id')->map(fn (\$id) => (string) \$id)->all();
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  \$model
     * @return Builder<T>
     */
    public function query(string \$model, Organization \$company): Builder
    {
        return \$model::inTenantOf(\$company)
            ->withoutGlobalScope(OrganizationScope::class)
            ->where((new \$model)->qualifyColumn('organization_id'), \$company->getKey());
    }

    /**
     * @template T
     *
     * @param  callable(): T  \$callback
     * @return T
     */
    public function transaction(Organization \$company, callable \$callback): mixed
    {
        \$connection = \$this->databases->forOrganization(\$company);

        return \$connection === \$this->databases->central()
            ? DB::transaction(\$callback)
            : DB::connection(\$connection)->transaction(fn () => DB::transaction(\$callback));
    }

    public function currency(Organization \$company): string
    {
        return \$this->settings->values(\$company)['currency_code'] ?? throw {$this->studly}Exception::noCurrency();
    }
}

PHP;
    }

    /** @param  array<string, mixed>  $entity */
    private function model(array $entity): string
    {
        $fields = $entity['fields'];
        $translatable = array_values(array_map(fn ($field) => $field['column'], array_filter($fields, fn ($field) => $field['type'] === 'translatable')));
        $money = array_filter($fields, fn ($field) => $field['type'] === 'money') !== [];
        $fillable = ['organization_id', 'unit_id', ...array_map(fn ($field) => $field['column'], array_values(array_filter($fields, fn ($field) => $field['type'] !== 'translatable'))),
            ...($money ? ['currency'] : []), 'created_by', 'op_id', 'version'];
        $casts = ['version' => 'integer'];
        foreach ($fields as $field) {
            $cast = match ($field['type']) {
                'integer', 'quantity', 'money' => 'integer',
                'date' => 'immutable_date',
                'boolean' => 'boolean',
                default => null,
            };
            if ($cast !== null) {
                $casts[$field['column']] = $cast;
            }
        }
        $fillableText = implode(', ', array_map(fn ($name) => "'{$name}'", $fillable));
        $castsText = implode(', ', array_map(fn ($name, $cast) => "'{$name}' => '{$cast}'", array_keys($casts), $casts));
        $traitUse = $translatable === [] ? '' : "use App\\Platform\\Support\\HasTranslatedTexts;\n";
        $traits = $translatable === [] ? 'BelongsToOrganization, HasUlids, UsesTenantDatabase' : 'BelongsToOrganization, HasTranslatedTexts, HasUlids, UsesTenantDatabase';
        $translatableProp = $translatable === [] ? '' : "\n    /** @var list<string> */\n    public array \$translatable = [".implode(', ', array_map(fn ($name) => "'{$name}'", $translatable))."];\n";
        $label = $entity['label']['en'];

        return <<<PHP
<?php

namespace Modules\\{$this->studly}\\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
{$traitUse}use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * {$label} (made by make:erp-module).
 */
#[Fillable([{$fillableText}])]
class {$entity['class']} extends Model
{
    use {$traits};

    protected \$table = '{$entity['table']}';

    protected \$attributes = ['version' => 1];
{$translatableProp}
    protected function casts(): array
    {
        return [{$castsText}];
    }
}

PHP;
    }

    /** @param  array<string, mixed>  $entity */
    private function service(array $entity): string
    {
        $fields = $entity['fields'];
        $hasEmployee = array_filter($fields, fn ($field) => $field['type'] === 'employee') !== [];
        $hasMoney = array_filter($fields, fn ($field) => $field['type'] === 'money') !== [];
        $plain = array_values(array_map(fn ($field) => $field['column'], array_filter($fields, fn ($field) => $field['type'] !== 'translatable')));
        $plainText = implode(', ', array_map(fn ($name) => "'{$name}'", $plain));
        $translatable = array_values(array_filter($fields, fn ($field) => $field['type'] === 'translatable'));
        $checks = '';
        foreach ($fields as $field) {
            if ($field['type'] === 'belongs_to') {
                $target = $this->definition->entities()[$field['entity']];
                $checks .= <<<PHP

        if ((\$data['{$field['column']}'] ?? null) !== null
            && ! \$this->store->query({$target['class']}::class, \$company)->whereKey(\$data['{$field['column']}'])->whereIn('unit_id', \$this->store->subtreeIds(\$unit))->exists()) {
            throw {$this->studly}Exception::badLink('{$field['column']}');
        }
PHP;
            }
            if ($field['type'] === 'employee') {
                $checks .= <<<PHP

        if ((\$data['{$field['column']}'] ?? null) !== null) {
            \$employee = \$this->employees->find(\$company, \$data['{$field['column']}']);
            if (\$employee === null || ! in_array(\$employee->unitId, \$this->store->subtreeIds(\$unit), true)) {
                throw {$this->studly}Exception::badLink('{$field['column']}');
            }
        }
PHP;
            }
        }
        $unique = '';
        foreach ($fields as $field) {
            if ($field['unique']) {
                $unique .= <<<PHP

            if (\$record->{$field['column']} !== null && \$this->store->query({$entity['class']}::class, \$company)->where('{$field['column']}', \$record->{$field['column']})
                ->when(\$record->exists, fn (\$query) => \$query->whereKeyNot(\$record->getKey()))->exists()) {
                throw ValidationException::withMessages(['{$field['column']}' => __('{$this->key}::messages.validation.taken')]);
            }
PHP;
            }
        }
        $texts = '';
        foreach ($translatable as $field) {
            $texts .= "\n            if (array_key_exists('{$field['column']}', \$data)) {\n                \$record->putTexts('{$field['column']}', \$data['{$field['column']}']);\n            }";
        }
        $users = [];
        foreach ($this->definition->entities() as $other) {
            foreach ($other['fields'] as $field) {
                if ($field['type'] === 'belongs_to' && $field['entity'] === $entity['name']) {
                    $users[] = [$other['class'], $field['column']];
                }
            }
        }
        $inUse = '';
        foreach ($users as [$class, $column]) {
            $inUse .= "\n            if (\$this->store->query({$class}::class, \$company)->where('{$column}', \$record->getKey())->exists()) {\n                throw {$this->studly}Exception::inUse();\n            }";
        }
        $models = array_unique([$entity['class'], ...array_map(fn ($user) => $user[0], $users),
            ...array_map(fn ($field) => $this->definition->entities()[$field['entity']]['class'], array_filter($fields, fn ($field) => $field['type'] === 'belongs_to'))]);
        sort($models);
        $modelUses = implode("\n", array_map(fn ($class) => "use Modules\\{$this->studly}\\Models\\{$class};", $models));
        $employeeUse = $hasEmployee ? "use Modules\\Hrm\\Directory\\EmployeeDirectory;\n" : '';
        $employeeArg = $hasEmployee ? ', private EmployeeDirectory $employees' : '';
        $currency = $hasMoney ? "\n                \$record->currency = \$this->store->currency(\$company);" : '';
        $title = $entity['title'];
        $titleColumn = $fields[$title]['column'];
        $titleText = $fields[$title]['type'] === 'translatable' ? "\$record->textIn('{$titleColumn}')" : "(string) \$record->{$titleColumn}";
        $label = $entity['label']['en'];

        return <<<PHP
<?php

namespace Modules\\{$this->studly}\\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\\{$this->studly}\\Exceptions\\{$this->studly}Exception;
{$modelUses}
{$employeeUse}
/**
 * {$entity['plural']['en']}: made at a unit (an op id makes one tap make one),
 * changed with their version, removed with their version when nothing else
 * uses them. Links must be the company's and within the unit. Audited.
 */
class {$entity['plural_class']}
{
    public function __construct(private {$this->studly}Store \$store, private AuditLogger \$audit{$employeeArg}) {}

    /**
     * @param  array<string, mixed>  \$data  Validated by {$entity['class']}Request.
     */
    public function save(Organization \$company, Organization \$unit, ?{$entity['class']} \$record, array \$data, User \$actor): {$entity['class']}
    {
        if (\$record === null && (\$data['op_id'] ?? null) !== null) {
            \$done = \$this->store->query({$entity['class']}::class, \$company)->where('op_id', \$data['op_id'])->first();
            if (\$done !== null) {
                return \$done;
            }
        }{$checks}

        return \$this->store->transaction(\$company, function () use (\$company, \$unit, \$record, \$data, \$actor) {
            if (\$record !== null) {
                \$record = \$this->store->query({$entity['class']}::class, \$company)->whereKey(\$record->getKey())->lockForUpdate()->firstOrFail();
                if (\$record->version !== (int) \$data['base_version']) {
                    throw {$this->studly}Exception::versionConflict(['version' => \$record->version]);
                }
                \$old = \$record->attributesToArray();
                \$record->fill(array_intersect_key(\$data, array_flip([{$plainText}])));
                \$record->version++;
            } else {
                \$old = null;
                \$record = new {$entity['class']}([
                    'organization_id' => \$company->getKey(), 'unit_id' => \$unit->getKey(), 'created_by' => \$actor->getKey(), 'op_id' => \$data['op_id'] ?? null,
                    ...array_intersect_key(\$data, array_flip([{$plainText}])),
                ]);{$currency}
            }{$texts}{$unique}
            \$record->save();
            \$this->audit->record(\$old === null ? '{$this->key}.{$entity['name']}_created' : '{$this->key}.{$entity['name']}_updated', \$record,
                old: \$old ?? [], new: \$record->attributesToArray(), actor: \$actor, organizationId: \$company->getKey());

            return \$record;
        });
    }

    /** Removed for good, unless other records use it. */
    public function delete(Organization \$company, {$entity['class']} \$record, int \$baseVersion, User \$actor): void
    {
        \$this->store->transaction(\$company, function () use (\$company, \$record, \$baseVersion, \$actor) {
            \$record = \$this->store->query({$entity['class']}::class, \$company)->whereKey(\$record->getKey())->lockForUpdate()->firstOrFail();
            if (\$record->version !== \$baseVersion) {
                throw {$this->studly}Exception::versionConflict(['version' => \$record->version]);
            }{$inUse}
            \$this->audit->record('{$this->key}.{$entity['name']}_deleted', \$record, old: \$record->attributesToArray(), actor: \$actor, organizationId: \$company->getKey());
            \$record->delete();
        });
    }

    /** The title a list or a link shows for a {$label}. */
    public static function title({$entity['class']} \$record): string
    {
        return {$titleText};
    }
}

PHP;
    }

    /** @param  array<string, mixed>  $entity */
    private function request(array $entity): string
    {
        $rules = '';
        foreach ($entity['fields'] as $field) {
            $need = $field['required'] ? "\$creating ? 'required' : 'sometimes'" : "'sometimes', 'nullable'";
            $column = $field['column'];
            $rules .= match ($field['type']) {
                'translatable' => "\n            '{$column}' => [{$need}, 'array:'.implode(',', \$locales)],\n            '{$column}.en' => [".($field['required'] ? "\$creating ? 'required' : 'sometimes'" : "'nullable'").", 'string', 'max:".($field['max'] ?? 150)."'],\n            '{$column}.*' => ['nullable', 'string', 'max:".($field['max'] ?? 150)."'],",
                'text' => "\n            '{$column}' => [{$need}, 'string', 'max:{$field['max']}'],",
                'long_text' => "\n            '{$column}' => [{$need}, 'string', 'max:5000'],",
                'integer' => "\n            '{$column}' => [{$need}, 'integer', 'min:-1000000000000000', 'max:1000000000000000'],",
                'quantity' => "\n            '{$column}' => [{$need}, 'integer', 'min:-1000000000000000', 'max:1000000000000000'],",
                'money' => "\n            '{$column}' => [{$need}, 'integer', 'min:0', 'max:100000000000000'],",
                'date' => "\n            '{$column}' => [{$need}, 'date_format:Y-m-d'],",
                'boolean' => "\n            '{$column}' => [".($field['required'] ? "\$creating ? 'required' : 'sometimes'" : "'sometimes'").", 'boolean'],",
                'enum' => "\n            '{$column}' => [{$need}, 'in:".implode(',', array_keys($field['options']))."'],",
                'belongs_to', 'employee' => "\n            '{$column}' => [{$need}, 'string', 'size:26'],",
            };
        }
        $locales = array_filter($entity['fields'], fn ($field) => $field['type'] === 'translatable') !== [];
        $localeUse = $locales ? "use App\\Platform\\Localization\\LanguageRegistry;\n" : '';
        $localeVar = $locales ? "\n        \$locales = LanguageRegistry::codes();" : '';

        return <<<PHP
<?php

namespace Modules\\{$this->studly}\\Http\\Requests;

{$localeUse}use App\Platform\Support\Http\StrictFormRequest;

/**
 * Making or changing a {$entity['label']['en']}: its fields (money in minor units, quantities in thousandths), an op id when made, its version when changed.
 */
class {$entity['class']}Request extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        \$creating = \$this->isMethod('post');{$localeVar}

        return [{$rules}
            'op_id' => [\$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [\$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}

PHP;
    }

    /** @param  array<string, mixed>  $entity */
    private function controller(array $entity): string
    {
        $fields = $entity['fields'];
        $param = $entity['name'];
        $filterRules = '';
        $filterWhere = '';
        foreach ($entity['filters'] as $name) {
            $field = $fields[$name];
            $column = $field['column'];
            $rule = match ($field['type']) {
                'enum' => "'in:".implode(',', array_keys($field['options']))."'",
                'boolean' => "'boolean'",
                default => "'string', 'size:26'",
            };
            $filterRules .= ", '{$column}' => ['nullable', {$rule}]";
            $filterWhere .= "\n            ->when(isset(\$filters['{$column}']), fn (\$query) => \$query->where('{$column}', ".($field['type'] === 'boolean' ? "(bool) \$filters['{$column}']" : "\$filters['{$column}']").'))';
        }
        $search = '';
        if ($entity['search'] !== []) {
            $ors = implode('', array_map(fn ($index, $name) => ($index === 0 ? '$inner->where' : '->orWhere')."('{$fields[$name]['column']}', 'like', \$like)", array_keys($entity['search']), $entity['search']));
            $search = "\n            ->when(\$needle !== '', function (\$query) use (\$needle) {\n                \$like = '%'.addcslashes(\$needle, '%_\\\\').'%';\n                \$query->where(fn (\$inner) => {$ors});\n            })";
        }
        $presenter = lcfirst($entity['class']);

        return <<<PHP
<?php

namespace Modules\\{$this->studly}\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\\{$this->studly}\\Exceptions\\{$this->studly}Exception;
use Modules\\{$this->studly}\\Http\\Controllers\\Concerns\\Finds{$this->studly};
use Modules\\{$this->studly}\\Http\\{$this->studly}Presenter;
use Modules\\{$this->studly}\\Http\\Requests\\{$entity['class']}Request;
use Modules\\{$this->studly}\\Models\\{$entity['class']};
use Modules\\{$this->studly}\\Services\\{$entity['plural_class']};
use Modules\\{$this->studly}\\Services\\{$this->studly}Store;

/**
 * {$entity['plural']['en']} of the unit and the units below it: listed (search, filters, pages) and read with
 * {$this->key}.view; made, changed and removed with {$this->key}.manage.
 */
class {$entity['class']}Controller extends Controller
{
    use Finds{$this->studly};

    public function __construct(private {$this->studly}Store \$store, private {$entity['plural_class']} \$records, private {$this->studly}Presenter \$presenter) {}

    public function index(Request \$request, string \$organization): JsonResponse
    {
        [\$unit, \$company] = \$this->workplace(\$organization);
        Gate::authorize('{$this->key}.view', \$unit);
        \$filters = \$request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']{$filterRules},
        ]);
        \$needle = trim((string) (\$filters['q'] ?? ''));
        \$page = \$this->store->query({$entity['class']}::class, \$company)->whereIn('unit_id', \$this->store->subtreeIds(\$unit)){$search}{$filterWhere}
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(PerPage::from(\$request, 25));

        return response()->json([
            'data' => collect(\$page->items())->map(fn ({$entity['class']} \$record) => \$this->presenter->{$presenter}(\$record))->values(),
            'meta' => ['total' => \$page->total(), 'page' => \$page->currentPage(), 'last_page' => \$page->lastPage()],
        ]);
    }

    public function show(string \$organization, string \${$param}): JsonResponse
    {
        [\$unit, \$company] = \$this->workplace(\$organization);
        Gate::authorize('{$this->key}.view', \$unit);

        return response()->json(['data' => \$this->presenter->{$presenter}(\$this->find(\$unit, \$company, \${$param}))]);
    }

    public function store({$entity['class']}Request \$request, string \$organization): JsonResponse
    {
        [\$unit, \$company] = \$this->workplace(\$organization);
        Gate::authorize('{$this->key}.manage', \$unit);
        \$record = \$this->records->save(\$company, \$unit, null, \$request->validated(), \$request->user());

        return response()->json(['data' => \$this->presenter->{$presenter}(\$record)], \$record->wasRecentlyCreated ? 201 : 200);
    }

    public function update({$entity['class']}Request \$request, string \$organization, string \${$param}): JsonResponse
    {
        [\$unit, \$company] = \$this->workplace(\$organization);
        \$found = \$this->find(\$unit, \$company, \${$param});
        Gate::authorize('{$this->key}.manage', \$unit);

        return response()->json(['data' => \$this->presenter->{$presenter}(\$this->records->save(\$company, \$unit, \$found, \$request->validated(), \$request->user()))]);
    }

    public function destroy(Request \$request, string \$organization, string \${$param}): Response
    {
        [\$unit, \$company] = \$this->workplace(\$organization);
        \$found = \$this->find(\$unit, \$company, \${$param});
        Gate::authorize('{$this->key}.manage', \$unit);
        \$version = (int) \$request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        \$this->records->delete(\$company, \$found, \$version, \$request->user());

        return response()->noContent();
    }

    private function find(\$unit, \$company, string \$id): {$entity['class']}
    {
        return \$this->store->query({$entity['class']}::class, \$company)->whereKey(\$id)->whereIn('unit_id', \$this->store->subtreeIds(\$unit))->first()
            ?? throw {$this->studly}Exception::notFound('{$entity['name']}');
    }
}

PHP;
    }

    private function presenter(): string
    {
        $methods = '';
        $uses = [];
        foreach ($this->definition->entities() as $entity) {
            $uses[] = "use Modules\\{$this->studly}\\Models\\{$entity['class']};";
            $parts = ["'id' => \$record->getKey()", "'unit_id' => \$record->unit_id"];
            foreach ($entity['fields'] as $field) {
                $column = $field['column'];
                $parts[] = match ($field['type']) {
                    'translatable' => "'{$column}' => (object) \$record->texts('{$column}')",
                    'date' => "'{$column}' => \$record->{$column}?->toDateString()",
                    default => "'{$column}' => \$record->{$column}",
                };
            }
            if (array_filter($entity['fields'], fn ($field) => $field['type'] === 'money') !== []) {
                $parts[] = "'currency' => \$record->currency";
            }
            $parts[] = "'version' => \$record->version";
            $parts[] = "'created_at' => \$record->created_at?->toIso8601String()";
            $body = implode(",\n            ", $parts);
            $method = lcfirst($entity['class']);
            $methods .= <<<PHP

    /** @return array<string, mixed> */
    public function {$method}({$entity['class']} \$record): array
    {
        return [
            {$body},
        ];
    }

PHP;
        }
        sort($uses);
        $useText = implode("\n", $uses);

        return <<<PHP
<?php

namespace Modules\\{$this->studly}\\Http;

{$useText}

/** The module's records as the screens read them (made by make:erp-module). */
class {$this->studly}Presenter
{{$methods}}

PHP;
    }

    private function finds(): string
    {
        return <<<PHP
<?php

namespace Modules\\{$this->studly}\\Http\\Controllers\\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\\{$this->studly}\\Services\\{$this->studly}Store;

/**
 * The unit in the address (visible to the context) and its company; anything else is the same 404.
 */
trait Finds{$this->studly}
{
    use FindsVisibleOrganizations;

    /** @return array{0: Organization, 1: Organization} */
    protected function workplace(string \$organization): array
    {
        \$unit = \$this->findVisible(\$organization);

        return [\$unit, app({$this->studly}Store::class)->companyOf(\$unit)];
    }
}

PHP;
    }

    private function setupController(): string
    {
        $options = '';
        $uses = [];
        $targets = [];
        $employees = false;
        foreach ($this->definition->entities() as $entity) {
            foreach ($entity['fields'] as $field) {
                if ($field['type'] === 'belongs_to') {
                    $targets[$field['entity']] = $this->definition->entities()[$field['entity']];
                }
                if ($field['type'] === 'employee') {
                    $employees = true;
                }
            }
        }
        foreach ($targets as $name => $target) {
            $uses[] = "use Modules\\{$this->studly}\\Models\\{$target['class']};";
            $uses[] = "use Modules\\{$this->studly}\\Services\\{$target['plural_class']};";
            $options .= "\n                '{$name}' => (object) \$this->store->query({$target['class']}::class, \$company)->whereIn('unit_id', \$units)->orderBy('created_at')->limit(1000)->get()\n                    ->mapWithKeys(fn ({$target['class']} \$record) => [\$record->getKey() => {$target['plural_class']}::title(\$record)])->all(),";
        }
        if ($employees) {
            $uses[] = 'use Modules\\Hrm\\Directory\\EmployeeDirectory;';
            $uses[] = 'use Modules\\Hrm\\Directory\\EmployeeRecord;';
        }
        sort($uses);
        $useText = $uses === [] ? '' : implode("\n", $uses)."\n";
        $employeeLine = $employees
            ? "\n            'employees' => (object) collect(app(EmployeeDirectory::class)->inUnits(\$company, \$units, null, 2000))->mapWithKeys(fn (EmployeeRecord \$employee) => [\$employee->id => \$employee->name])->all(),"
            : '';

        return <<<PHP
<?php

namespace Modules\\{$this->studly}\\Http\\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\\{$this->studly}\\Http\\Controllers\\Concerns\\Finds{$this->studly};
use Modules\\{$this->studly}\\Services\\{$this->studly}Store;
{$useText}
/**
 * What the module's screens need once (read with {$this->key}.view): whether the person may write, the company's
 * currency, and the titles of records other records link to (and employees' names) at the unit and below.
 */
class SetupController extends Controller
{
    use Finds{$this->studly};

    public function __construct(private {$this->studly}Store \$store) {}

    public function __invoke(string \$organization): JsonResponse
    {
        [\$unit, \$company] = \$this->workplace(\$organization);
        Gate::authorize('{$this->key}.view', \$unit);
        \$units = \$this->store->subtreeIds(\$unit);

        return response()->json(['data' => [
            'can' => ['manage' => Gate::allows('{$this->key}.manage', \$unit)],
            'currency' => rescue(fn () => \$this->store->currency(\$company), null, false),
            'options' => [{$options}
            ],{$employeeLine}
        ]]);
    }
}

PHP;
    }

    private function routes(): string
    {
        $reads = '';
        $writes = '';
        $uses = ["use Modules\\{$this->studly}\\Http\\Controllers\\SetupController;"];
        foreach ($this->definition->entities() as $entity) {
            $path = str_replace('_', '-', $entity['plural_name']);
            $controller = "{$entity['class']}Controller";
            $uses[] = "use Modules\\{$this->studly}\\Http\\Controllers\\{$controller};";
            $reads .= "\n        Route::get('{$path}', [{$controller}::class, 'index']);\n        Route::get('{$path}/{{$entity['name']}}', [{$controller}::class, 'show']);";
            $writes .= "\n            Route::post('{$path}', [{$controller}::class, 'store']);\n            Route::patch('{$path}/{{$entity['name']}}', [{$controller}::class, 'update']);\n            Route::delete('{$path}/{{$entity['name']}}', [{$controller}::class, 'destroy']);";
        }
        sort($uses);
        $useText = implode("\n", $uses);

        return <<<PHP
<?php

use Illuminate\Support\Facades\Route;
{$useText}

/*
| {$this->definition->data['name']['en']} API (made by make:erp-module): a signed-in organization context, the module
| on there (403 otherwise), and the permission checked in each controller at the unit in the address.
*/

Route::middleware(['auth:sanctum', 'org', 'module:{$this->key}'])
    ->prefix('organizations/{organization}/{$this->slug}')
    ->group(function () {
        Route::get('setup', SetupController::class);{$reads}

        Route::middleware('throttle:{$this->slug}-writes')->group(function () {{$writes}
        });
    });

PHP;
    }

    private function migration(): string
    {
        $up = '';
        $down = '';
        foreach ($this->definition->entities() as $entity) {
            $columns = '';
            foreach ($entity['fields'] as $field) {
                $nullable = $field['required'] && $field['type'] !== 'boolean' ? '' : '->nullable()';
                $column = $field['column'];
                $columns .= match ($field['type']) {
                    'text' => "\n            \$table->string('{$column}', {$field['max']}){$nullable};",
                    'long_text' => "\n            \$table->text('{$column}'){$nullable};",
                    'translatable' => "\n            \$table->json('{$column}'){$nullable};",
                    'integer', 'quantity' => "\n            \$table->bigInteger('{$column}'){$nullable};",
                    'money' => "\n            \$table->unsignedBigInteger('{$column}'){$nullable};",
                    'date' => "\n            \$table->date('{$column}'){$nullable};",
                    'boolean' => "\n            \$table->boolean('{$column}')->default(false);",
                    'enum' => "\n            \$table->string('{$column}', 30){$nullable};",
                    'belongs_to', 'employee' => "\n            \$table->ulid('{$column}'){$nullable};",
                };
            }
            if (array_filter($entity['fields'], fn ($field) => $field['type'] === 'money') !== []) {
                $columns .= "\n            \$table->char('currency', 3)->nullable();";
            }
            $indexes = "\n            \$table->unique(['organization_id', 'op_id'], '{$entity['table']}_op_unique');\n            \$table->index(['organization_id', 'unit_id'], '{$entity['table']}_unit_index');";
            foreach ($entity['fields'] as $field) {
                if ($field['unique']) {
                    $indexes .= "\n            \$table->unique(['organization_id', '{$field['column']}'], '{$entity['table']}_{$field['column']}_unique');";
                }
                if (in_array($field['type'], ['belongs_to', 'employee', 'enum'], true)) {
                    $indexes .= "\n            \$table->index(['organization_id', '{$field['column']}'], '{$entity['table']}_{$field['column']}_index');";
                }
            }
            $up .= <<<PHP

        Schema::create('{$entity['table']}', function (Blueprint \$table) {
            \$table->ulid('id')->primary();
            \$table->ulid('organization_id');
            \$table->ulid('unit_id');{$columns}
            \$table->ulid('created_by');
            \$table->string('op_id', 64)->nullable();
            \$table->unsignedInteger('version')->default(1);
            \$table->timestamps();
{$indexes}
        });

PHP;
            $down = "        Schema::dropIfExists('{$entity['table']}');\n".$down;
        }

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * {$this->definition->data['name']['en']} (made by make:erp-module), in the client's database: one table per
 * entity, each record with its company and unit, a version and an op id.
 */
return new class extends Migration
{
    public function up(): void
    {{$up}    }

    public function down(): void
    {
{$down}    }
};

PHP;
    }

    private function schemaJs(): string
    {
        $entities = [];
        foreach ($this->definition->entities() as $entity) {
            $fields = [];
            foreach ($entity['fields'] as $field) {
                $fields[] = array_filter([
                    'name' => $field['name'], 'column' => $field['column'], 'type' => $field['type'], 'required' => $field['required'],
                    'max' => $field['max'], 'options' => $field['type'] === 'enum' ? array_keys($field['options']) : null, 'entity' => $field['entity'] ?? null,
                ], fn ($value) => $value !== null);
            }
            $entities[] = [
                'name' => $entity['name'], 'path' => str_replace('_', '-', $entity['plural_name']), 'title' => $entity['title'],
                'titleColumn' => $entity['fields'][$entity['title']]['column'], 'search' => $entity['search'], 'filters' => $entity['filters'],
                'columns' => $entity['columns'], 'fields' => $fields,
            ];
        }
        $json = json_encode(['key' => $this->key, 'slug' => $this->slug, 'entities' => $entities], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = preg_replace('/^(?: {4})+/m', '$0', $json);

        return "/**\n * {$this->definition->data['name']['en']}: what the shared generated screens show (made by make:erp-module).\n * Change fields here and in the module's migration, request and presenter together.\n */\nexport default {$json};\n";
    }

    private function routesJs(): string
    {
        return <<<JS
import schema from './schema';

/**
 * {$this->definition->data['name']['en']} screens (made by make:erp-module): one list per entity, from the shared
 * generated list page; texts from the "{$this->key}" and "generated" namespaces.
 */
const meta = { context: 'organization', ns: ['{$this->key}', 'generated'], module: '{$this->key}' };
const page = () => import('@/components/generated/GeneratedListPage.vue');

export default schema.entities.map((entity) => ({
    path: `\${schema.slug}/\${entity.path}`,
    name: `\${schema.key}-\${entity.name}`,
    component: page,
    props: { schema, entity: entity.name },
    meta,
}));

JS;
    }
}
