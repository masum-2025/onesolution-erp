<?php

namespace App\Platform\PageExports;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\PageExports\Exceptions\PageExportException;
use App\Platform\Rules\RuleContext;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\Gate;

/**
 * Export and print on pages (the browser builds the file from what the page
 * already shows; the server decides and remembers). What a page may offer
 * comes from rules (ui.export_formats, ui.print_enabled, ui.export_modules_off,
 * ui.export_max_rows) and from who may see it:
 *
 *  - a module's pages: the module is on; one that declares "{module}.export"
 *    (sensitive data: payroll, HRM) offers export only to people holding it;
 *  - the platform's own pages ("core.{page}"): the permission the page needs,
 *    from CORE_PAGES (never from the request);
 *  - the partner console ("partner.{page}"): the partner roles of PARTNER_PAGES,
 *    with the partner's own rules.
 *
 * Every export and print is audited.
 */
class PageExports
{
    public const FORMATS = ['pdf', 'xlsx', 'csv', 'docx'];

    /** The platform's own pages that export, and the permission each needs. */
    public const CORE_PAGES = [
        'core.organizations' => 'organizations.manage',
        'core.roles' => 'roles.manage',
        'core.approvals' => 'rules.approve',
        'core.support_access' => 'support.approve',
    ];

    /** Partner console pages that export, and the partner roles that may. */
    public const PARTNER_PAGES = [
        'partner.clients' => [PartnerUserRole::Owner, PartnerUserRole::Sales],
        'partner.billing' => [PartnerUserRole::Owner, PartnerUserRole::Billing],
        'partner.transfers' => [PartnerUserRole::Owner, PartnerUserRole::Sales],
        'partner.support' => [PartnerUserRole::Owner, PartnerUserRole::Support],
        'partner.api_keys' => [PartnerUserRole::Owner],
        'partner.website_media' => [PartnerUserRole::Owner, PartnerUserRole::Sales],
        'partner.website_leads' => [PartnerUserRole::Owner, PartnerUserRole::Sales],
    ];

    private const NONE = ['formats' => [], 'print' => false, 'max_rows' => 0];

    public function __construct(
        private ModuleRegistry $modules,
        private ModuleResolver $resolver,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * What a module's pages (or a "core." page) may offer to this person here.
     *
     * @return array{formats: list<string>, print: bool, max_rows: int}
     */
    public function settings(Organization $organization, string $module): array
    {
        if (str_starts_with($module, 'core.')) {
            $permission = self::CORE_PAGES[$module] ?? null;

            return $permission !== null && Gate::allows($permission, $organization)
                ? $this->allowed($this->contexts->forOrganization($organization), $module)
                : self::NONE;
        }

        $definition = $this->modules->all()[$module] ?? null;
        if ($definition === null || ! $this->resolver->resolve($module, $organization)->enabled) {
            return self::NONE;
        }
        $permission = "{$module}.export";
        if (in_array($permission, $definition->permissions, true) && ! Gate::allows($permission, $organization)) {
            return self::NONE;
        }

        return $this->allowed($this->contexts->forOrganization($organization), $module);
    }

    /**
     * What a partner console page may offer to this partner person.
     *
     * @return array{formats: list<string>, print: bool, max_rows: int}
     */
    public function partnerSettings(Partner $partner, PartnerUserRole $role, string $page): array
    {
        return in_array($role, self::PARTNER_PAGES[$page] ?? [], true)
            ? $this->allowed($this->contexts->forPartner($partner), $page)
            : self::NONE;
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
        $this->check($allowed, $data);
        $this->audit->record('page_exported', null, new: [
            'module' => $data['module'], 'page' => $data['page'], 'format' => $data['format'], 'rows' => $data['rows'],
        ], actor: $actor, organizationId: $organization->getKey());

        return ['max_rows' => $allowed['max_rows']];
    }

    /**
     * A partner console export: checked again for this partner and role, audited on the partner.
     *
     * @param  array{page: string, format: string, rows: int}  $data
     * @return array{max_rows: int}
     */
    public function recordForPartner(Partner $partner, PartnerUserRole $role, array $data, User $actor): array
    {
        $allowed = $this->partnerSettings($partner, $role, $data['page']);
        $this->check($allowed, $data);
        $this->audit->record('page_exported', null, new: [
            'module' => 'partner', 'page' => $data['page'], 'format' => $data['format'], 'rows' => $data['rows'],
        ], actor: $actor, partnerId: $partner->getKey());

        return ['max_rows' => $allowed['max_rows']];
    }

    /**
     * The rules' answer for a page that may export at all: formats, print and rows,
     * or nothing when the page's key is switched off (ui.export_modules_off).
     *
     * @return array{formats: list<string>, print: bool, max_rows: int}
     */
    private function allowed(RuleContext $context, string $key): array
    {
        if (in_array($key, (array) $this->rules->get('ui.export_modules_off', $context), true)) {
            return self::NONE;
        }

        return [
            'formats' => array_values(array_intersect(self::FORMATS, (array) $this->rules->get('ui.export_formats', $context))),
            'print' => (bool) $this->rules->get('ui.print_enabled', $context),
            'max_rows' => (int) $this->rules->get('ui.export_max_rows', $context),
        ];
    }

    /**
     * @param  array{formats: list<string>, print: bool, max_rows: int}  $allowed
     * @param  array{format: string, rows: int}  $data
     */
    private function check(array $allowed, array $data): void
    {
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
    }
}
