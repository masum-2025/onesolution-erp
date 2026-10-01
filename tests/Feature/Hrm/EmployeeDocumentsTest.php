<?php

use App\Platform\Audit\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * HRM-1: employee documents: kinds and size from the rules, private files,
 * opened only through short-lived signed links, every step audited.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->w = hrmWorld($this);
    $this->employee = hireVia($this, $this->w->token, $this->w->c1)->json('data.id');
    $this->base = "/api/organizations/{$this->w->c1->id}/hrm/employees/{$this->employee}/documents";
});

function upload(object $test, array $data = [], ?UploadedFile $file = null)
{
    return $test->asToken($test->w->token)->post($test->base, [
        'file' => $file ?? UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'),
        'type' => 'contract',
        'title' => 'Employment contract',
        ...$data,
    ], ['Accept' => 'application/json']);
}

it('keeps a document privately and opens it only through a short-lived signed link', function () {
    $document = upload($this)->assertCreated()->assertJsonPath('data.type', 'contract')->json('data.id');

    $files = Storage::disk('local')->allFiles("hrm/{$this->w->c1->id}/{$this->employee}");
    expect($files)->toHaveCount(1);

    $url = $this->asToken($this->w->token)->getJson("{$this->base}/{$document}/link")->assertOk()->json('data.url');
    expect($url)->toStartWith('/files/hrm/'.$this->w->c1->id.'/'.$document);

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
    expect(AuditLog::query()->where('action', 'hrm.document_downloaded')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'hrm.document_added')->exists())->toBeTrue();

    // A changed or expired link opens nothing.
    $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();
});

it('takes only the kinds and sizes the rules allow, and real PDFs or images', function () {
    orgRule($this->w->c1, 'hrm.document_types', ['contract']);
    // Size limits belong to the plan or above (storage costs), not the company.
    platformRule('hrm.document_max_kb', 100);

    upload($this, ['type' => 'certificate'])->assertUnprocessable()->assertJsonValidationErrors('type');
    upload($this)->assertUnprocessable()->assertJsonValidationErrors('file');
    upload($this, file: UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))->assertUnprocessable()->assertJsonValidationErrors('file');
    upload($this, file: UploadedFile::fake()->image('photo.png'))->assertCreated();
    upload($this, ['owner' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('owner');
});

it('never lists, links or removes another company\'s documents', function () {
    $document = upload($this)->json('data.id');
    $c2Token = orgToken(createMember($this->w->c2), $this->w->c2);

    $this->asToken($c2Token)->getJson("/api/organizations/{$this->w->c2->id}/hrm/employees/{$this->employee}/documents")->assertNotFound();
    $this->asToken($c2Token)->getJson("/api/organizations/{$this->w->c2->id}/hrm/employees/{$this->employee}/documents/{$document}/link")->assertNotFound();
    $this->asToken($c2Token)->deleteJson("/api/organizations/{$this->w->c2->id}/hrm/employees/{$this->employee}/documents/{$document}")->assertNotFound();
});

it('removes a document and its file, audited, for people who may manage HRM', function () {
    $document = upload($this)->json('data.id');
    $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['hrm.view'], 'Reader'));

    $this->asToken(orgToken($clerk, $this->w->c1))->deleteJson("{$this->base}/{$document}")->assertForbidden();
    $this->asToken($this->w->token)->deleteJson("{$this->base}/{$document}", ['reason' => 'Uploaded by mistake'])->assertOk();

    expect(Storage::disk('local')->allFiles("hrm/{$this->w->c1->id}"))->toBe([])
        ->and($this->asToken($this->w->token)->getJson($this->base)->json('data'))->toBe([])
        ->and(AuditLog::query()->where('action', 'hrm.document_removed')->sole()->reason)->toBe('Uploaded by mistake');
});
