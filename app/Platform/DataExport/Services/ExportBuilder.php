<?php

namespace App\Platform\DataExport\Services;

use App\Platform\Access\Models\MembershipRole;
use App\Platform\Access\Models\Role;
use App\Platform\Audit\AuditLog;
use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Modules\Models\OrganizationModule;
use App\Platform\Packaging\Models\OrganizationPackage;
use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use RuntimeException;
use ZipArchive;

/**
 * Writes one ZIP with every dataset of an organization and the units below
 * it, each as JSON and CSV, plus a manifest. Platform datasets come from
 * the platform's own tables; module data only through ExportsModuleData.
 * Passwords, tokens and other secrets are never part of any dataset.
 */
class ExportBuilder
{
    public const FORMAT_VERSION = 1;

    /**
     * @param  iterable<ExportsModuleData>  $exporters
     */
    public function __construct(private iterable $exporters = []) {}

    /**
     * @return array{datasets: array<string, int>} Row count per dataset.
     */
    public function build(Organization $organization, string $absolutePath): array
    {
        $ids = Organization::query()->subtreeOf($organization)->pluck('id')->all();
        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }

        $counts = [];
        foreach ($this->platformDatasets($ids) as $name => $rows) {
            $counts["platform/{$name}"] = $this->add($zip, "platform/{$name}", $rows);
        }

        foreach ($this->exporters as $exporter) {
            foreach ($exporter->export($organization, $ids) as $name => $rows) {
                $counts["modules/{$exporter->moduleKey()}/{$name}"] = $this->add($zip, "modules/{$exporter->moduleKey()}/{$name}", $rows);
            }
        }

        $zip->addFromString('manifest.json', json_encode([
            'format_version' => self::FORMAT_VERSION,
            'generated_at' => now()->toIso8601String(),
            'organization' => ['id' => $organization->getKey(), 'name' => $organization->texts('name')],
            'datasets' => $counts,
            'notes' => 'Every dataset is included twice: .json (exact values) and .csv (for spreadsheets). Money is in integer minor units with its currency code. Times are UTC.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $zip->close();

        return ['datasets' => $counts];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, iterable<array<string, mixed>>>
     */
    private function platformDatasets(array $ids): array
    {
        $membershipIds = OrganizationMembership::query()->whereIn('organization_id', $ids)->pluck('id');
        $roleIds = Role::query()->whereIn('organization_id', $ids)->pluck('id');

        return [
            'organizations' => Organization::query()->whereIn('id', $ids)->orderBy('depth')->get()->map(fn (Organization $o) => [
                'id' => $o->id, 'parent_id' => $o->parent_id, 'type' => $o->type->value, 'name' => $o->texts('name'),
                'sector_key' => $o->sector_key, 'country_code' => $o->country_code, 'currency_code' => $o->currency_code,
                'timezone' => $o->timezone, 'default_locale' => $o->default_locale, 'status' => $o->status->value,
                'plan_key' => $o->plan_key, 'created_at' => $o->created_at?->toIso8601String(),
            ]),
            // People: names and emails only; never passwords or tokens.
            'members' => OrganizationMembership::query()->with('user:id,name,email')->whereIn('organization_id', $ids)->get()->map(fn (OrganizationMembership $m) => [
                'id' => $m->id, 'organization_id' => $m->organization_id, 'user_id' => $m->user_id,
                'name' => $m->user?->name, 'email' => $m->user?->email, 'membership_type' => $m->membership_type->value,
                'access_scope' => $m->access_scope->value, 'status' => $m->status->value, 'created_at' => $m->created_at?->toIso8601String(),
            ]),
            'roles' => Role::query()->whereIn('organization_id', $ids)->get()->map(fn (Role $r) => [
                'id' => $r->id, 'organization_id' => $r->organization_id, 'key' => $r->key, 'name' => $r->texts('name'),
                'template_key' => $r->template_key, 'permissions' => $r->permissionKeys(),
            ]),
            'role_assignments' => MembershipRole::query()->whereIn('membership_id', $membershipIds)->get(['id', 'organization_id', 'membership_id', 'role_id', 'created_at'])
                ->map(fn (MembershipRole $a) => $a->attributesToArray()),
            'module_settings' => OrganizationModule::query()->whereIn('organization_id', $ids)->get()->map(fn (OrganizationModule $m) => [
                'organization_id' => $m->organization_id, 'module_key' => $m->module_key, 'state' => $m->state->value, 'locked' => $m->locked,
            ]),
            'rule_values' => RuleValue::query()
                ->whereIn('scope_type', [RuleScope::Group, RuleScope::Company, RuleScope::Branch, RuleScope::Department])
                ->whereIn('scope_id', $ids)
                ->orderBy('rule_key')
                ->get()
                ->map(fn (RuleValue $v) => [
                    'id' => $v->id, 'rule_key' => $v->rule_key, 'level' => $v->scope_type->value, 'organization_id' => $v->scope_id,
                    'country_code' => $v->country_code, 'mode' => $v->mode->value, 'value' => $v->value, 'status' => $v->status->value,
                    'version' => $v->version, 'effective_from' => $v->effective_from?->toIso8601String(), 'effective_to' => $v->effective_to?->toIso8601String(),
                ]),
            'sector_packages' => OrganizationPackage::query()->whereIn('organization_id', $ids)->get()->map(fn (OrganizationPackage $p) => [
                'organization_id' => $p->organization_id, 'package_key' => $p->package_key, 'version' => $p->package_version,
                'summary' => $p->summary, 'applied_at' => $p->created_at?->toIso8601String(),
            ]),
            // The client's own history, without network details (IP, browser).
            'audit_log' => AuditLog::query()->whereIn('organization_id', $ids)->orderBy('created_at')->cursor()->map(fn (AuditLog $e) => [
                'id' => $e->id, 'organization_id' => $e->organization_id, 'actor_user_id' => $e->actor_user_id, 'action' => $e->action,
                'target_type' => $e->target_type, 'target_id' => $e->target_id, 'old_values' => $e->old_values,
                'new_values' => $e->new_values, 'reason' => $e->reason, 'created_at' => $e->created_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function add(ZipArchive $zip, string $name, iterable $rows): int
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = $row;
        }

        $zip->addFromString("{$name}.json", json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString("{$name}.csv", $this->csv($list));

        return count($list);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $headers = array_keys($rows[0]);
        $handle = fopen('php://temp', 'r+');
        // A BOM so spreadsheet apps read Bangla text as UTF-8.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($value) => match (true) {
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
                is_bool($value) => $value ? 'true' : 'false',
                // Spreadsheet formula injection: text starting with = + - @ is quoted.
                is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) => "'".$value,
                default => $value,
            }, array_map(fn (string $key) => $row[$key] ?? null, $headers)), escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }
}
