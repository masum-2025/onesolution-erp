<?php

namespace Modules\Hrm\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\EmployeeDocument;

/**
 * Files kept for employees: checked against the unit's rules (kinds, size),
 * stored on the private disk under the company, opened only through a
 * short-lived signed link; adding, opening and removing are audited.
 */
class EmployeeDocuments
{
    /** Kinds of files accepted, by their content (not their name). */
    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public const LINK_MINUTES = 5;

    public function __construct(
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{type: string, title: string, expires_on?: string|null}  $data
     */
    public function add(Employee $employee, UploadedFile $file, array $data, User $actor): EmployeeDocument
    {
        $context = $this->contexts->forOrganization(Organization::query()->findOrFail($employee->organization_id));
        $maxKb = (int) $this->rules->get('hrm.document_max_kb', $context);
        $errors = [];

        if (! in_array($data['type'], (array) $this->rules->get('hrm.document_types', $context), true)) {
            $errors['type'] = __('hrm::hrm.validation.document_type');
        }
        if ($file->getSize() > $maxKb * 1024) {
            $errors['file'] = __('hrm::hrm.validation.document_size', ['max' => $maxKb]);
        }
        if (! in_array($file->getMimeType(), self::MIME_TYPES, true)) {
            $errors['file'] = __('validation.mimetypes', ['attribute' => 'file', 'values' => 'PDF, JPG, PNG, WebP']);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $path = $file->storeAs(
            "hrm/{$employee->company_id}/{$employee->getKey()}",
            Str::ulid().'.'.($file->guessExtension() ?? 'bin'),
            'local',
        );

        $document = EmployeeDocument::query()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->getKey(),
            'type' => $data['type'],
            'title' => $data['title'],
            'file_path' => $path,
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'expires_on' => $data['expires_on'] ?? null,
            'uploaded_by' => $actor->getKey(),
        ]);

        $this->audit->record('hrm.document_added', $document, new: ['employee_id' => $employee->getKey(), 'type' => $document->type], actor: $actor, organizationId: $employee->organization_id);

        return $document;
    }

    /** A link that opens the file for a few minutes (routes/web.php of the module). */
    public function link(EmployeeDocument $document): string
    {
        return URL::temporarySignedRoute('hrm.documents.download', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $document->organization_id,
            'document' => $document->getKey(),
        ], absolute: false);
    }

    public function recordDownload(EmployeeDocument $document, ?User $actor): void
    {
        $this->audit->record('hrm.document_downloaded', $document, new: ['employee_id' => $document->employee_id], actor: $actor, organizationId: $document->organization_id);
    }

    public function remove(EmployeeDocument $document, User $actor, ?string $reason): void
    {
        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        $this->audit->record('hrm.document_removed', $document, old: ['employee_id' => $document->employee_id, 'type' => $document->type], reason: $reason, actor: $actor, organizationId: $document->organization_id);
    }
}
