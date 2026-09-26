<?php

use App\Platform\Audit\AuditLog;
use App\Platform\DataExport\Models\DataExport;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerStatus;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 5B-2 acceptance: a client can export all its data at any time, also
 * while its partner is suspended; the file holds no secrets and is only
 * reachable through a short-lived signed link.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->exports = "http://localhost/api/organizations/{$this->w->c1->id}/exports";
});

function readZip(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();

    return $files;
}

it('exports all data of the organization and its units', function () {
    createMember($this->w->b1, MembershipType::Staff);

    $this->asToken($this->token)->postJson($this->exports)->assertStatus(202);

    $export = $this->asToken($this->token)->getJson($this->exports)->assertOk()->json('data.0');
    expect($export['status'])->toBe('ready')
        ->and($export['datasets']['platform/members'])->toBe(2)
        ->and($export['datasets']['platform/organizations'])->toBe(3);

    $record = DataExport::find($export['id']);
    $files = readZip(Storage::disk('local')->path($record->file_path));

    expect(array_keys($files))->toContain('manifest.json', 'platform/organizations.json', 'platform/organizations.csv', 'platform/members.csv', 'platform/audit_log.json')
        ->and(json_decode($files['manifest.json'], true)['format_version'])->toBe(1)
        ->and($files['platform/members.json'])->toContain($this->owner->email)
        // No secrets, and nothing of other companies.
        ->and($files['platform/members.json'])->not->toContain('password')
        ->and($files['platform/organizations.json'])->not->toContain($this->w->c2->id)
        ->and($files['platform/audit_log.json'])->not->toContain('ip_address');
});

it('downloads only through a short-lived signed link, and logs the download', function () {
    $this->asToken($this->token)->postJson($this->exports)->assertStatus(202);
    $id = DataExport::sole()->id;

    $url = $this->asToken($this->token)->getJson("{$this->exports}/{$id}/link")->assertOk()->json('data.url');

    $this->get("http://localhost/exports/{$id}/download")->assertForbidden();
    $this->get('http://localhost'.$url.'x')->assertForbidden();
    $this->get('http://localhost'.$url)->assertOk()->assertHeader('Content-Type', 'application/zip');

    expect(AuditLog::where('action', 'data.export_downloaded')->where('organization_id', $this->w->c1->id)->exists())->toBeTrue();

    $this->travel(6)->minutes();
    $this->get('http://localhost'.$url)->assertForbidden();
});

it('runs one export at a time and needs the export permission', function () {
    DataExport::create(['organization_id' => $this->w->c1->id, 'requested_by' => $this->owner->id, 'status' => DataExport::QUEUED]);
    $this->asToken($this->token)->postJson($this->exports)->assertUnprocessable()->assertJsonPath('code', 'already_running');

    $staff = createMember($this->w->c1, MembershipType::Staff);
    $this->asToken(orgToken($staff, $this->w->c1))->postJson($this->exports)->assertForbidden();
});

it('never gives one organization another\'s export', function () {
    $this->asToken($this->token)->postJson($this->exports)->assertStatus(202);
    $id = DataExport::sole()->id;

    $c2Owner = createMember($this->w->c2);
    $this->asToken(orgToken($c2Owner, $this->w->c2))->getJson("http://localhost/api/organizations/{$this->w->c2->id}/exports/{$id}/link")->assertNotFound();
    $this->asToken(orgToken($c2Owner, $this->w->c2))->getJson("http://localhost/api/organizations/{$this->w->c1->id}/exports")->assertNotFound();
});

it('deletes files after the retention period', function () {
    $this->asToken($this->token)->postJson($this->exports)->assertStatus(202);
    $export = DataExport::sole();

    $this->travel(8)->days();
    $this->artisan('exports:prune')->assertSuccessful();

    expect(Storage::disk('local')->exists($export->file_path))->toBeFalse()
        ->and($export->fresh()->status)->toBe('expired');
});

// ── A suspended partner's clients ──

it('keeps a suspended partner\'s clients reading and exporting, then exporting only', function () {
    $this->w->partnerA->forceFill(['status' => PartnerStatus::Suspended, 'suspended_at' => now()])->save();

    // In the grace period: read-only.
    $this->asToken($this->token)->getJson('http://localhost/api/organizations')->assertOk();
    $this->asToken($this->token)->patchJson("http://localhost/api/organizations/{$this->w->c1->id}", ['name' => ['en' => 'X']])
        ->assertForbidden()->assertJsonPath('code', 'read_only_partner_suspended');
    $this->asToken($this->token)->postJson($this->exports)->assertStatus(202);

    // After the grace period (default 30 days): export only.
    $this->travel(31)->days();
    $token = orgToken($this->owner, $this->w->c1);
    $this->asToken($token)->getJson('http://localhost/api/organizations')->assertForbidden()->assertJsonPath('code', 'export_only');
    $this->asToken($token)->getJson($this->exports)->assertOk();
    DataExport::query()->update(['status' => DataExport::EXPIRED]);
    $this->asToken($token)->postJson($this->exports)->assertStatus(202);

    // Nothing was deleted.
    expect($this->w->c1->fresh())->not->toBeNull();
});

it('suspends and reactivates partners from the command line with a reason', function () {
    $this->artisan('partners:status', ['action' => 'suspend', 'partner' => $this->w->partnerA->slug, '--reason' => 'Invoices unpaid since June'])->assertSuccessful();
    expect($this->w->partnerA->fresh()->status)->toBe(PartnerStatus::Suspended)
        ->and($this->w->partnerA->fresh()->suspended_at)->not->toBeNull();

    $this->artisan('partners:status', ['action' => 'reactivate', 'partner' => $this->w->partnerA->slug, '--reason' => 'Paid in full'])->assertSuccessful();
    expect($this->w->partnerA->fresh()->status)->toBe(PartnerStatus::Active)
        ->and(AuditLog::whereIn('action', ['partner.suspended', 'partner.reactivated'])->count())->toBe(2);

    $this->artisan('partners:status', ['action' => 'suspend', 'partner' => $this->w->partnerA->slug])->assertFailed();
});
