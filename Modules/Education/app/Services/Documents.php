<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Education\Events\DocumentIssued;
use Modules\Education\Events\DocumentRevoked;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Models\Document;
use Modules\Education\Models\DocumentTemplate;
use Modules\Education\Models\Session;
use Modules\Education\Models\Student;

/**
 * Issuing ID cards, certificates and letters, revoking them, and finding
 * one by its verification code.
 *
 * - Issued with an active design to one student or many at once (all or
 *   nothing): a number by education.number_prefixes, a random code for the
 *   QR check, and the design, values and photo copied as they are now, so
 *   the document prints the same later.
 * - ID cards are for students studying now, valid by the rule
 *   education.id_card_valid_months (0: to the end of their session). A
 *   student keeps one valid card: a new one replaces the old only when asked.
 * - Revoked with a reason, never deleted. Every issue and revoke is audited
 *   (without the values printed).
 * - Sent with an op id, a retried issue gives the documents made the first time.
 */
class Documents
{
    public const MAX_STUDENTS = 500;

    public const LINK_MINUTES = 30;

    /** Letters of verification codes: no 0/O or 1/I to misread. */
    private const CODE_LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 12;

    public function __construct(
        private Education $education,
        private Numbers $numbers,
        private DocumentLayout $layout,
        private DocumentValues $values,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  list<Student>  $students  Students the issuer may see (checked by the caller).
     * @param  array<string, mixed>  $inputs
     * @return list<Document>
     */
    public function issue(Organization $company, DocumentTemplate $template, array $students, array $inputs, ?string $issuedOn, bool $replace, ?string $opId, User $actor): array
    {
        if ($template->status !== 'active') {
            throw EducationException::templateNotActive();
        }
        $inputs = $this->values->inputs((array) ($template->inputs ?? []), $inputs);
        $day = $issuedOn !== null ? CarbonImmutable::parse($issuedOn) : $this->education->today($company);
        $sensitive = $this->layout->isSensitive($company, $template->layout);
        $withPhoto = collect($template->layout['elements'] ?? [])->contains('type', 'photo');
        $months = (int) $this->rules->get('education.id_card_valid_months', $this->contexts->forOrganization($company));

        $made = $this->education->transaction($company, function () use ($company, $template, $students, $inputs, $day, $replace, $opId, $actor, $sensitive, $withPhoto, $months) {
            $documents = [];
            foreach ($students as $student) {
                $op = $opId === null ? null : mb_substr("{$opId}:{$student->getKey()}", 0, 64);
                if ($op !== null && ($existing = $this->education->query(Document::class, $company)->where('op_id', $op)->first()) !== null) {
                    $documents[] = $existing;

                    continue;
                }

                $replaced = null;
                if ($template->kind === 'id_card') {
                    if ($student->status !== 'active') {
                        throw EducationException::notActiveStudent($student->name, __("education::education.document.statuses.{$student->status}"));
                    }
                    $replaced = $this->validCard($company, $student, $day);
                    if ($replaced !== null && ! $replace) {
                        throw EducationException::documentExists($student->name, __('education::education.document.kinds.id_card'), $replaced->number, $student->getKey());
                    }
                }

                $validUntil = $template->kind === 'id_card' ? $this->cardValidUntil($company, $student, $day, $months) : null;
                $document = new Document;
                $document->id = (string) Str::ulid();
                $number = $this->numbers->documentNumber($company, $template->kind, (int) $day->format('Y'));
                $values = $this->values->for($company, $student, $template->only(['kind', 'locale', 'layout', 'inputs']), $inputs, ['number' => $number, 'date' => $day, 'valid_until' => $validUntil]);
                $document->fill([
                    'organization_id' => $company->getKey(),
                    'unit_id' => $student->unit_id,
                    'template_id' => $template->getKey(),
                    'template_version' => $template->version,
                    'kind' => $template->kind,
                    'student_id' => $student->getKey(),
                    'number' => $number,
                    'code' => $this->newCode($company),
                    'locale' => $template->locale,
                    'snapshot' => ['page' => $template->page, 'layout' => $template->layout, 'values' => $values, 'summary' => $this->values->summary($company, $student, $template->locale)],
                    'has_sensitive' => $sensitive,
                    'photo_path' => $withPhoto ? $this->copyPhoto($company, $student, $document->id) : null,
                    'issued_on' => $day->toDateString(),
                    'valid_until' => $validUntil?->toDateString(),
                    'issued_by' => $actor->getKey(),
                    'op_id' => $op,
                ]);
                $document->putTexts('title', $template->texts('name'));
                $document->save();
                $this->audit->record('education.document_issued', $document, new: [...$document->only(['kind', 'number', 'student_id', 'template_id', 'template_version']), 'issued_on' => $day->toDateString()],
                    actor: $actor, organizationId: $company->getKey());

                if ($replaced !== null) {
                    $this->markRevoked($company, $replaced, __('education::education.document.replaced_by', ['number' => $number], $template->locale), $actor);
                }
                $documents[] = $document;
            }

            return $documents;
        });

        foreach ($made as $document) {
            if ($document->wasRecentlyCreated) {
                DB::afterCommit(fn () => event(new DocumentIssued($company->getKey(), $document->getKey(), $document->student_id, $document->kind)));
            }
        }

        return $made;
    }

    public function revoke(Organization $company, Document $document, string $reason, User $actor): Document
    {
        return $this->education->transaction($company, function () use ($company, $document, $reason, $actor) {
            /** @var Document $document */
            $document = $this->education->query(Document::class, $company)->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            if ($document->revoked_at !== null) {
                throw EducationException::documentRevoked();
            }

            return $this->markRevoked($company, $document, $reason, $actor);
        });
    }

    /** The document of an institution with this verification code (letters in any case, spaces and dashes ignored). */
    public function byCode(Organization $company, string $code): ?Document
    {
        $code = strtoupper((string) preg_replace('/[\s-]/', '', $code));
        if (preg_match('/^['.self::CODE_LETTERS.']{'.self::CODE_LENGTH.'}$/', $code) !== 1) {
            return null;
        }

        return $this->education->query(Document::class, $company)->where('code', $code)->first();
    }

    /** Where the QR check of a document lives, on the address it was opened at. */
    public static function verifyPath(Document $document): string
    {
        return "/verify/{$document->organization_id}/{$document->code}";
    }

    /** The QR code of a link, as an SVG data address. */
    public function qr(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 0), new SvgImageBackEnd)))->writeString($url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** A link that shows the photo copied at issue, for a while (long enough to print). */
    public function photoLink(Document $document): ?string
    {
        return $document->photo_path === null ? null : URL::temporarySignedRoute('education.document-photo', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $document->organization_id,
            'document' => $document->getKey(),
        ], absolute: false);
    }

    /** The student's valid ID card on a day, if any. */
    private function validCard(Organization $company, Student $student, CarbonImmutable $day): ?Document
    {
        return $this->education->query(Document::class, $company)->where('student_id', $student->getKey())->where('kind', 'id_card')
            ->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $day->toDateString()))
            ->lockForUpdate()->orderByDesc('issued_on')->first();
    }

    private function cardValidUntil(Organization $company, Student $student, CarbonImmutable $day, int $months): ?CarbonImmutable
    {
        if ($months > 0) {
            return $day->addMonthsNoOverflow($months)->subDay();
        }
        $enrollment = $this->values->enrollment($company, $student);
        $session = $enrollment === null ? null : $this->education->query(Session::class, $company)->whereKey($enrollment->session_id)->first();

        return $session?->ends_on === null ? null : CarbonImmutable::parse($session->ends_on);
    }

    private function newCode(Organization $company): string
    {
        do {
            $code = '';
            for ($index = 0; $index < self::CODE_LENGTH; $index++) {
                $code .= self::CODE_LETTERS[random_int(0, strlen(self::CODE_LETTERS) - 1)];
            }
        } while ($this->education->query(Document::class, $company)->where('code', $code)->exists());

        return $code;
    }

    /** The student's photo as it is now, copied for the document (a later photo never changes it). */
    private function copyPhoto(Organization $company, Student $student, string $documentId): ?string
    {
        if ($student->photo_path === null || ! Storage::disk('local')->exists($student->photo_path)) {
            return null;
        }
        $path = "education/{$company->getKey()}/documents/{$documentId}.".pathinfo($student->photo_path, PATHINFO_EXTENSION);
        Storage::disk('local')->copy($student->photo_path, $path);

        return $path;
    }

    private function markRevoked(Organization $company, Document $document, string $reason, User $actor): Document
    {
        $document->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->getKey(), 'revoke_reason' => mb_substr($reason, 0, 300)])->save();
        $this->audit->record('education.document_revoked', $document, new: ['number' => $document->number, 'reason' => $document->revoke_reason], actor: $actor, organizationId: $company->getKey());
        DB::afterCommit(fn () => event(new DocumentRevoked($company->getKey(), $document->getKey(), $document->student_id)));

        return $document;
    }
}
