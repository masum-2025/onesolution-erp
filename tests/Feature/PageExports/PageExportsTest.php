<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Carbon\CarbonImmutable;

/*
 * Export and print on module pages: what a module's pages may offer comes
 * from rules (formats, print, modules kept out, rows) and, for a module that
 * declares "{module}.export", from that permission. Every export is checked
 * again and audited before the browser builds the file.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'));
    $this->w = accountingWorld();
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->w->token);
    $this->send = fn (array $data, ?string $token = null) => ($this->as)($token)->postJson('/api/page-exports', [
        'module' => 'accounting', 'page' => 'accounting.journals', 'format' => 'xlsx', 'rows' => 40, ...$data,
    ]);
    $this->withHeader('Origin', config('app.url'));
});

it('offers every format and print by default, and audits each export', function () {
    ($this->as)()->getJson('/api/page-exports?module=accounting')->assertOk()
        ->assertJsonPath('data', ['formats' => ['pdf', 'xlsx', 'csv', 'docx'], 'print' => true, 'max_rows' => 5000]);

    ($this->send)([])->assertCreated()->assertJsonPath('data.max_rows', 5000);
    ($this->send)(['format' => 'print', 'rows' => 0])->assertCreated();
    $logged = AuditLog::query()->where('action', 'page_exported')->orderBy('created_at')->get();
    expect($logged)->toHaveCount(2)
        ->and($logged[0]->new_values)->toMatchArray(['module' => 'accounting', 'page' => 'accounting.journals', 'format' => 'xlsx', 'rows' => 40])
        ->and($logged[0]->actor_user_id)->toBe($this->w->accountant->id);
});

it('follows the rules: formats, print, modules kept out, rows', function () {
    trustedOrgRule($this->w->g1, 'ui.export_formats', ['csv']);
    trustedOrgRule($this->w->c1, 'ui.export_max_rows', 100);
    ($this->as)()->getJson('/api/page-exports?module=accounting')->assertJsonPath('data', ['formats' => ['csv'], 'print' => true, 'max_rows' => 100]);
    ($this->send)([])->assertForbidden()->assertJsonPath('code', 'page_format_off');
    ($this->send)(['format' => 'csv', 'rows' => 101])->assertUnprocessable()->assertJsonPath('code', 'page_too_many_rows')->assertJsonPath('max_rows', 100);
    ($this->send)(['format' => 'csv', 'rows' => 100])->assertCreated();

    trustedOrgRule($this->w->c1, 'ui.print_enabled', false);
    ($this->send)(['format' => 'print'])->assertForbidden();

    trustedOrgRule($this->w->c1, 'ui.export_modules_off', ['accounting']);
    ($this->as)()->getJson('/api/page-exports?module=accounting')->assertJsonPath('data', ['formats' => [], 'print' => false, 'max_rows' => 0]);
    ($this->send)(['format' => 'csv'])->assertForbidden()->assertJsonPath('code', 'page_export_off');
    expect(AuditLog::query()->where('action', 'page_exported')->count())->toBe(1);
});

it('offers nothing for a module that is off or unknown, and checks the input', function () {
    toggles()->disable($this->w->c1, 'accounting', 'Test');
    ($this->as)()->getJson('/api/page-exports?module=accounting')->assertJsonPath('data.formats', []);
    ($this->as)()->getJson('/api/page-exports?module=no_such_module')->assertJsonPath('data.formats', []);
    ($this->send)(['module' => 'no_such_module'])->assertForbidden();
    ($this->send)(['format' => 'xls'])->assertUnprocessable()->assertJsonValidationErrors('format');
    ($this->send)(['page' => '<script>'])->assertUnprocessable()->assertJsonValidationErrors('page');
    ($this->send)(['extra' => 1])->assertUnprocessable();
});

it('asks for the module\'s export permission where it declares one (payroll)', function () {
    toggles()->enable($this->w->g1, 'hrm', 'Test setup');
    toggles()->enable($this->w->g1, 'payroll', 'Test setup');
    $clerk = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.run'], 'Clerk')), $this->w->c1);
    $exporter = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['payroll.view', 'payroll.export'], 'Payroll exporter')), $this->w->c1);

    ($this->as)($clerk)->getJson('/api/page-exports?module=payroll')->assertJsonPath('data.formats', [])->assertJsonPath('data.print', false);
    ($this->send)(['module' => 'payroll', 'page' => 'payroll.runs'], $clerk)->assertForbidden();
    ($this->as)($exporter)->getJson('/api/page-exports?module=payroll')->assertJsonPath('data.print', true);
    ($this->send)(['module' => 'payroll', 'page' => 'payroll.runs'], $exporter)->assertCreated();
});

it('asks for hrm.export on HRM pages: people\'s details', function () {
    toggles()->enable($this->w->g1, 'hrm', 'Test setup');
    $viewer = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.manage'], 'HR viewer')), $this->w->c1);
    $exporter = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view', 'hrm.export'], 'HR exporter')), $this->w->c1);

    ($this->as)($viewer)->getJson('/api/page-exports?module=hrm')->assertJsonPath('data.formats', [])->assertJsonPath('data.print', false);
    ($this->send)(['module' => 'hrm', 'page' => 'hrm.employees'], $viewer)->assertForbidden();
    ($this->as)($exporter)->getJson('/api/page-exports?module=hrm')->assertJsonPath('data.print', true);
    ($this->send)(['module' => 'hrm', 'page' => 'hrm.employees'], $exporter)->assertCreated();

    toggles()->disable($this->w->c1, 'hrm', 'Test');
    ($this->as)($exporter)->getJson('/api/page-exports?module=hrm')->assertJsonPath('data.formats', []);
    ($this->send)(['module' => 'hrm', 'page' => 'hrm.employees'], $exporter)->assertForbidden();
    expect(AuditLog::query()->where('action', 'page_exported')->pluck('organization_id')->all())->toBe([$this->w->c1->id]);

    // Other HR modules without their own export permission: anyone who sees the page.
    toggles()->enable($this->w->c1, 'hrm', 'Test setup');
    toggles()->enable($this->w->g1, 'attendance', 'Test setup');
    ($this->as)($viewer)->getJson('/api/page-exports?module=attendance')->assertJsonPath('data.print', true);
});

it('keeps each organization to its own rules and its own audit', function () {
    trustedOrgRule($this->w->c2, 'ui.export_modules_off', ['accounting']);
    // C2's switch does not reach C1.
    ($this->as)()->getJson('/api/page-exports?module=accounting')->assertJsonPath('data.print', true);
    ($this->send)([])->assertCreated();
    expect(AuditLog::query()->where('action', 'page_exported')->sole()->organization_id)->toBe($this->w->c1->id);
});

it('lets the platform\'s own pages export only for people holding what the page needs (step C)', function () {
    $roleKeeper = orgToken(staffWithRoles($this->w->c1, makeRole($this->w->c1, ['roles.manage'], 'Role keeper')), $this->w->c1);

    // The accountant may not manage roles: nothing offered, nothing taken.
    ($this->as)()->getJson('/api/page-exports?module=core.roles')->assertJsonPath('data.formats', [])->assertJsonPath('data.print', false);
    ($this->send)(['module' => 'core.roles', 'page' => 'core.roles'])->assertForbidden();

    ($this->as)($roleKeeper)->getJson('/api/page-exports?module=core.roles')->assertJsonPath('data.print', true);
    ($this->send)(['module' => 'core.roles', 'page' => 'core.roles'], $roleKeeper)->assertCreated();
    // A page not on the platform's list offers nothing, whoever asks.
    ($this->as)($roleKeeper)->getJson('/api/page-exports?module=core.secrets')->assertJsonPath('data.formats', []);
    ($this->as)($roleKeeper)->getJson('/api/page-exports?module=core.Roles')->assertUnprocessable();

    trustedOrgRule($this->w->c1, 'ui.export_modules_off', ['core.roles']);
    ($this->send)(['module' => 'core.roles', 'page' => 'core.roles', 'format' => 'csv'], $roleKeeper)->assertForbidden();
    expect(AuditLog::query()->where('action', 'page_exported')->sole()->new_values)->toMatchArray(['module' => 'core.roles', 'page' => 'core.roles']);
});

it('lets partner console pages export by partner role and the partner\'s own rules, audited on the partner (step C)', function () {
    $owner = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $support = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Support), $this->w->partnerA);
    $otherOwner = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);
    $send = fn (string $token, array $data) => ($this->as)($token)->postJson('/api/partner/page-exports', ['page' => 'partner.billing', 'format' => 'xlsx', 'rows' => 10, ...$data]);

    ($this->as)($owner)->getJson('/api/partner/page-exports?page=partner.billing')->assertOk()->assertJsonPath('data.print', true);
    // Support staff may not see billing: nothing offered there, but their own support page exports.
    ($this->as)($support)->getJson('/api/partner/page-exports?page=partner.billing')->assertJsonPath('data.formats', []);
    $send($support, [])->assertForbidden();
    ($this->as)($support)->getJson('/api/partner/page-exports?page=partner.support')->assertJsonPath('data.print', true);
    // API keys: the owner only.
    ($this->as)($support)->getJson('/api/partner/page-exports?page=partner.api_keys')->assertJsonPath('data.formats', []);

    // The partner's own rules decide; another partner's do not reach it.
    partnerRule($this->w->partnerB, 'ui.export_formats', ['csv']);
    $send($owner, [])->assertCreated();
    partnerRule($this->w->partnerA, 'ui.export_formats', ['csv']);
    $send($owner, [])->assertForbidden()->assertJsonPath('code', 'page_format_off');
    $send($otherOwner, ['format' => 'csv'])->assertCreated();

    $logged = AuditLog::query()->where('action', 'page_exported')->orderBy('created_at')->get();
    expect($logged->pluck('partner_id')->all())->toBe([$this->w->partnerA->id, $this->w->partnerB->id])
        ->and($logged->pluck('organization_id')->unique()->all())->toBe([null])
        ->and($logged[0]->new_values)->toMatchArray(['module' => 'partner', 'page' => 'partner.billing', 'format' => 'xlsx']);

    // Only known pages and the expected fields.
    $send($owner, ['page' => 'partner.secrets'])->assertUnprocessable()->assertJsonValidationErrors('page');
    $send($owner, ['partner_id' => $this->w->partnerB->id])->assertUnprocessable();
    // Not a partner's console: the organization token does not reach it.
    ($this->as)()->getJson('/api/partner/page-exports?page=partner.billing')->assertForbidden();
});
