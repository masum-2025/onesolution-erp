<?php

namespace App\Platform\PageExports;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\PageExports\Exceptions\PageExportException;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;

/**
 * Export and print on module pages (the browser builds the file from what
 * the page already shows; the server decides and remembers). What a page
 * may offer comes from rules (ui.export_formats, ui.print_enabled,
 * ui.export_modules_off, ui.export_max_rows) and from the module: one that
 * declares the permission "{module}.export" (sensitive data: payroll, HRM)
 * offers export only to people holding it. Every export and print is
 * audited.
 */
class PageExports
{
    public const FORMATS = ['pdf', 'xlsx', 'csv', 'docx'];

    public function __construct(
        private ModuleRegistry $modules,
        private ModuleResolver $resolver,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * What a module's pages may offer to this person here.
     *
     * @return array{formats: list<string>, print: bool, max_rows: int}
     */
    public function settings(Organization $organization, string $module): array
    {
        $context = $this->contexts->forOrganization($organization);
        $none = ['formats' => [], 'print' => false, 'max_rows' => 0];
        $definition = $this->modules->all()[$module] ?? null;
        if ($definition === null || ! $this->resolver->resolve($module, $organization)->enabled) {
            return $none;
        }
        if (in_array($module, (array) $this->rules->get('ui.export_modules_off', $context), true)) {
            return $none;
        }
        $permission = "{$module}.export";
        if (in_array($permission, $definition->permissions, true) && ! Gate::allows($permission, $organization)) {
            return $none;
        }

        return [
            'formats' => array_values(array_intersect(self::FORMATS, (array) $this->rules->get('ui.export_formats', $context))),
            'print' => (bool) $this->rules->get('ui.print_enabled', $context),
            'max_rows' => (int) $this->rules->get('ui.export_max_rows', $context),
        ];
    }

    /**
     * Allowed now: checked again here and audited, before the browser builds the file.
     *
     * @param  array{module: string, page: string, format: string, rows: int}  $data
     * @return array{max_rows: int}
     */
    public function record(Organization $organization, array $data, User $actor): array
    {
        $allowed = $this->settings($organization, $data['module']);
        $format = $data['format'];
        if ($allowed['formats'] === [] && ! $allowed['print']) {
            throw PageExportException::off();
        }
        if ($format === 'print' ? ! $allowed['print'] : ! in_array($format, $allowed['formats'], true)) {
            throw PageExportException::formatOff();
        }
        if ($data['rows'] > $allowed['max_rows']) {
            throw PageExportException::tooManyRows($allowed['max_rows']);
        }

        $this->audit->record('page_exported', null, new: [
            'module' => $data['module'], 'page' => $data['page'], 'format' => $format, 'rows' => $data['rows'],
        ], actor: $actor, organizationId: $organization->getKey());

        return ['max_rows' => $allowed['max_rows']];
    }
}
