<?php

namespace Modules\EducationLectures\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationLectures\Events\LecturePublished;
use Modules\EducationLectures\Exceptions\LectureException;
use Modules\EducationLectures\Models\Lecture;
use Modules\EducationLectures\Models\LectureFile;
use ZipArchive;

/**
 * Posting, publishing and withdrawing lectures, and their files.
 *
 * - A teacher (education_lectures.post) posts only for a subject they teach
 *   in that section (Education's subject teachers); someone who manages
 *   lectures posts for any section of the campus.
 * - Publishing shows it to the section's students and guardians; with the
 *   rule education_lectures.approval_required a teacher's lecture waits for
 *   someone who manages lectures (never the one who posted it).
 * - A published lecture is withdrawn with a reason, never deleted; only a
 *   draft is deleted.
 * - Files: PDF, images, Word and PowerPoint (checked by their content), up
 *   to the rules' size and count, on the private disk; opened through a
 *   signed link that lasts minutes.
 */
class Lectures
{
    /** Minutes a file link works. */
    public const LINK_MINUTES = 10;

    /** Content types a lecture file may have (by what the file is, not its name). */
    public const MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    /** Hosts of each video site the rule education_lectures.video_hosts may allow. */
    public const VIDEO_HOSTS = [
        'youtube' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
        'google_drive' => ['drive.google.com'],
        'vimeo' => ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'],
        'facebook' => ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.watch'],
    ];

    public function __construct(
        private Classroom $classroom,
        private AcademicDirectory $academic,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * The sections whose lectures a person sees from a unit: null for all of
     * them (who manages lectures, or Education lets them see every student),
     * otherwise their own sections (class teacher or subject teacher).
     *
     * @return list<string>|null
     */
    public function visibleSections(Organization $company, Organization $unit, User $user): ?array
    {
        return Gate::forUser($user)->allows('education_lectures.manage', $unit) ? null : $this->academic->visibleSections($company, $unit, $user);
    }

    /** Whether a person may post for a subject of a section (manage: any; post: one they teach). */
    public function mayPost(Organization $company, Organization $unit, User $user, string $sectionId, string $subjectId): bool
    {
        if (Gate::forUser($user)->allows('education_lectures.manage', $unit)) {
            return true;
        }
        if (! Gate::forUser($user)->allows('education_lectures.post', $unit)) {
            return false;
        }
        $employee = $this->academic->employeeOf($company, $user);

        return $employee !== null && in_array(['section_id' => $sectionId, 'subject_id' => $subjectId], $this->academic->teaching($company, $employee, $this->classroom->subtreeIds($unit)), true);
    }

    /** Whether a person may change, publish or delete this lecture (who manages, or who posted it while they still teach it). */
    public function mayChange(Organization $company, Organization $unit, User $user, Lecture $lecture): bool
    {
        return Gate::forUser($user)->allows('education_lectures.manage', $unit)
            || ($lecture->posted_by === $user->getKey() && $this->mayPost($company, $unit, $user, $lecture->section_id, $lecture->subject_id));
    }

    /**
     * Post (lecture null) or change a lecture. A resent post with the same
     * op_id answers with the lecture it made.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Organization $company, Organization $unit, ?Lecture $lecture, array $data, User $actor): Lecture
    {
        if ($lecture === null && ($data['op_id'] ?? null) !== null) {
            $done = $this->classroom->query(Lecture::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        $sectionId = $lecture?->section_id ?? $data['section_id'];
        $subjectId = $lecture?->subject_id ?? $data['subject_id'];
        $section = $this->academic->section($company, $sectionId);
        if ($section === null || ! in_array($section['unit_id'], $this->classroom->subtreeIds($unit), true)) {
            throw LectureException::notFound('section');
        }
        if ($lecture === null) {
            $taught = array_column($this->academic->curriculum($company, $section['level_id'], $section['session_id']), 'subject_id');
            if (! in_array($subjectId, $taught, true)) {
                throw LectureException::subjectNotInSection();
            }
            if (! $this->mayPost($company, $unit, $actor, $sectionId, $subjectId)) {
                throw LectureException::notYourSubject();
            }
        } elseif (! $this->mayChange($company, $unit, $actor, $lecture)) {
            throw LectureException::notYourSubject();
        }
        if (array_key_exists('video_url', $data) && $data['video_url'] !== null) {
            $this->checkVideo($unit, $data['video_url']);
        }

        return $this->classroom->transaction($company, function () use ($company, $lecture, $data, $section, $sectionId, $subjectId, $actor) {
            if ($lecture !== null) {
                $lecture = $this->classroom->query(Lecture::class, $company)->whereKey($lecture->getKey())->lockForUpdate()->firstOrFail();
                if ($lecture->version !== (int) $data['base_version']) {
                    throw LectureException::versionConflict(['version' => $lecture->version]);
                }
                if ($lecture->status === 'withdrawn') {
                    throw LectureException::wrongStatus($lecture->status);
                }
                $old = $lecture->only(['lesson_on', 'title', 'body', 'video_url']);
                $lecture->fill(array_intersect_key($data, array_flip(['lesson_on', 'title', 'body', 'video_url'])));
                $lecture->version++;
            } else {
                $old = null;
                $teacher = collect($this->academic->subjectTeachers($company, [$sectionId]))->firstWhere('subject_id', $subjectId);
                $lecture = new Lecture([
                    'organization_id' => $company->getKey(), 'unit_id' => $section['unit_id'], 'session_id' => $section['session_id'],
                    'section_id' => $sectionId, 'subject_id' => $subjectId, 'teacher_id' => $teacher['teacher_id'] ?? null,
                    'posted_by' => $actor->getKey(), 'lesson_on' => $data['lesson_on'], 'title' => $data['title'],
                    'body' => $data['body'] ?? null, 'video_url' => $data['video_url'] ?? null, 'op_id' => $data['op_id'] ?? null,
                ]);
            }
            $lecture->save();
            $this->audit->record(
                $old === null ? 'education_lectures.created' : 'education_lectures.updated',
                $lecture,
                old: $old === null ? [] : $this->dated($old),
                new: $this->dated($lecture->only(['section_id', 'subject_id', 'lesson_on', 'title', 'video_url', 'status'])),
                actor: $actor,
                organizationId: $company->getKey(),
            );

            return $lecture;
        });
    }

    /** Show a draft to the section, or let it wait for approval when the institution asks for one. */
    public function publish(Organization $company, Organization $unit, Lecture $lecture, int $baseVersion, User $actor): Lecture
    {
        if (! $this->mayChange($company, $unit, $actor, $lecture)) {
            throw LectureException::notYourSubject();
        }
        $waits = (bool) $this->rules->get('education_lectures.approval_required', $this->contexts->forOrganization($unit))
            && ! Gate::forUser($actor)->allows('education_lectures.manage', $unit);

        return $this->move($company, $lecture, $baseVersion, ['draft'], $waits ? 'waiting' : 'published', $actor, $waits ? 'education_lectures.sent_for_approval' : 'education_lectures.published');
    }

    /** Publish a waiting lecture (someone who manages lectures, never the one who posted it). */
    public function approve(Organization $company, Organization $unit, Lecture $lecture, int $baseVersion, User $actor): Lecture
    {
        if (! Gate::forUser($actor)->allows('education_lectures.manage', $unit)) {
            throw LectureException::notYourSubject();
        }
        if ($lecture->posted_by === $actor->getKey()) {
            throw LectureException::ownApproval();
        }

        return $this->move($company, $lecture, $baseVersion, ['waiting'], 'published', $actor, 'education_lectures.approved');
    }

    /** Take a waiting or published lecture away from the section, with a reason; it stays on the list. */
    public function withdraw(Organization $company, Organization $unit, Lecture $lecture, int $baseVersion, string $reason, User $actor): Lecture
    {
        if (! $this->mayChange($company, $unit, $actor, $lecture)) {
            throw LectureException::notYourSubject();
        }

        return $this->move($company, $lecture, $baseVersion, ['waiting', 'published'], 'withdrawn', $actor, 'education_lectures.withdrawn', $reason);
    }

    /** A draft goes away with its files (nobody has seen it). */
    public function deleteDraft(Organization $company, Organization $unit, Lecture $lecture, User $actor): void
    {
        if (! $this->mayChange($company, $unit, $actor, $lecture)) {
            throw LectureException::notYourSubject();
        }
        if ($lecture->status !== 'draft') {
            throw LectureException::wrongStatus($lecture->status);
        }
        $this->classroom->transaction($company, function () use ($company, $lecture, $actor) {
            $files = $this->classroom->query(LectureFile::class, $company)->where('lecture_id', $lecture->getKey())->get();
            $this->audit->record('education_lectures.deleted', $lecture, old: $this->dated($lecture->only(['section_id', 'subject_id', 'lesson_on', 'title'])), actor: $actor, organizationId: $company->getKey());
            $this->classroom->query(LectureFile::class, $company)->where('lecture_id', $lecture->getKey())->delete();
            $lecture->delete();
            foreach ($files as $file) {
                Storage::disk('local')->delete($file->path);
            }
        });
    }

    /** Add a file: what it is (by content), how big, and how many a lecture may have are checked. */
    public function addFile(Organization $company, Organization $unit, Lecture $lecture, UploadedFile $upload, User $actor): LectureFile
    {
        if (! $this->mayChange($company, $unit, $actor, $lecture)) {
            throw LectureException::notYourSubject();
        }
        if ($lecture->status === 'withdrawn') {
            throw LectureException::wrongStatus($lecture->status);
        }
        $context = $this->contexts->forOrganization($unit);
        $maxKb = (int) $this->rules->get('education_lectures.max_file_kb', $context);
        $maxFiles = (int) $this->rules->get('education_lectures.max_files', $context);
        if ($this->classroom->query(LectureFile::class, $company)->where('lecture_id', $lecture->getKey())->count() >= $maxFiles) {
            throw LectureException::tooManyFiles($maxFiles);
        }
        if ($upload->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => __('education_lectures::lectures.validation.file_size', ['max' => intdiv($maxKb, 1024) ?: 1])]);
        }
        $mime = $this->contentType($upload);
        if ($mime === null) {
            throw ValidationException::withMessages(['file' => __('education_lectures::lectures.validation.file_type')]);
        }

        $path = $upload->storeAs("lectures/{$company->getKey()}/{$lecture->getKey()}", Str::ulid().'.'.self::MIME_TYPES[$mime], 'local');
        // A safe name to show and download under (any script with its vowel signs, no path or quote).
        $name = Str::limit(trim(preg_replace('/[^\pL\pM\pN ._()-]+/u', '_', pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME))) ?: 'file', 120, '').'.'.self::MIME_TYPES[$mime];

        $file = $this->classroom->query(LectureFile::class, $company)->create([
            'organization_id' => $company->getKey(), 'lecture_id' => $lecture->getKey(), 'name' => $name, 'mime' => $mime,
            'size_bytes' => (int) $upload->getSize(), 'path' => $path, 'checksum' => hash_file('sha256', $upload->getRealPath()), 'created_by' => $actor->getKey(),
        ]);
        $this->audit->record('education_lectures.file_added', $lecture, new: ['file' => $name, 'size_bytes' => $file->size_bytes], actor: $actor, organizationId: $company->getKey());

        return $file;
    }

    public function removeFile(Organization $company, Organization $unit, Lecture $lecture, LectureFile $file, User $actor): void
    {
        if (! $this->mayChange($company, $unit, $actor, $lecture)) {
            throw LectureException::notYourSubject();
        }
        if ($lecture->status === 'withdrawn') {
            throw LectureException::wrongStatus($lecture->status);
        }
        $this->audit->record('education_lectures.file_removed', $lecture, old: ['file' => $file->name], actor: $actor, organizationId: $company->getKey());
        $file->delete();
        Storage::disk('local')->delete($file->path);
    }

    /** A link that opens the file for a few minutes (routes/web.php of the module). */
    public function link(LectureFile $file): string
    {
        return URL::temporarySignedRoute('education_lectures.files.download', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $file->organization_id,
            'file' => $file->getKey(),
        ], absolute: false);
    }

    /**
     * @param  list<string>  $from
     */
    private function move(Organization $company, Lecture $lecture, int $baseVersion, array $from, string $to, User $actor, string $action, ?string $reason = null): Lecture
    {
        $lecture = $this->classroom->transaction($company, function () use ($company, $lecture, $baseVersion, $from, $to, $actor, $action, $reason) {
            $lecture = $this->classroom->query(Lecture::class, $company)->whereKey($lecture->getKey())->lockForUpdate()->firstOrFail();
            if ($lecture->version !== $baseVersion) {
                throw LectureException::versionConflict(['version' => $lecture->version]);
            }
            if (! in_array($lecture->status, $from, true)) {
                throw LectureException::wrongStatus($lecture->status);
            }
            $old = $lecture->status;
            $lecture->status = $to;
            if ($to === 'published') {
                $lecture->published_at = now();
                $lecture->approved_by = $old === 'waiting' ? $actor->getKey() : null;
            }
            if ($to === 'withdrawn') {
                $lecture->withdrawn_by = $actor->getKey();
                $lecture->withdrawn_reason = $reason;
            }
            $lecture->version++;
            $lecture->save();
            $this->audit->record($action, $lecture, old: ['status' => $old], new: ['status' => $to], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $lecture;
        });
        if ($to === 'published') {
            LecturePublished::dispatch((string) $company->getKey(), (string) $lecture->getKey());
        }

        return $lecture;
    }

    /** Only links to the video sites the institution allows, and only https. */
    private function checkVideo(Organization $unit, string $url): void
    {
        $allowed = collect((array) $this->rules->get('education_lectures.video_hosts', $this->contexts->forOrganization($unit)))
            ->flatMap(fn (string $site) => self::VIDEO_HOSTS[$site] ?? [])->all();
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! in_array(strtolower($parts['host'] ?? ''), $allowed, true) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['video_url' => __('education_lectures::lectures.validation.video', ['sites' => implode(', ', array_unique(array_map(fn (string $host) => preg_replace('/^(www|m|player)\./', '', $host), $allowed))) ?: '—'])]);
        }
    }

    /**
     * What the file is, from its content: Word and PowerPoint files are zip
     * archives, recognised by the parts inside them. Null for anything else.
     */
    private function contentType(UploadedFile $upload): ?string
    {
        // Read from the bytes themselves, never from the name or what the browser said.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());
        if (isset(self::MIME_TYPES[$mime]) && ! in_array(self::MIME_TYPES[$mime], ['docx', 'pptx'], true)) {
            return $mime;
        }
        if (! in_array($mime, ['application/zip', 'application/octet-stream', ...array_keys(array_filter(self::MIME_TYPES, fn (string $ext) => in_array($ext, ['docx', 'pptx'], true)))], true)) {
            return null;
        }
        $zip = new ZipArchive;
        if ($zip->open($upload->getRealPath(), ZipArchive::RDONLY) !== true) {
            return null;
        }
        $types = $zip->locateName('[Content_Types].xml') !== false;
        $kind = match (true) {
            $types && $zip->locateName('word/document.xml') !== false => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $types && $zip->locateName('ppt/presentation.xml') !== false => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => null,
        };
        $zip->close();

        return $kind;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function dated(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $values);
    }
}
