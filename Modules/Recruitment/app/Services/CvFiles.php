<?php

namespace Modules\Recruitment\Services;

use App\Models\User;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Models\CandidateFile;

/**
 * CVs and papers of applications: PDF and Word files (the kind read from
 * the file itself), at most recruitment.cv_max_kb, on the private disk;
 * shown only through a link signed for a few minutes; removed with the
 * application when the person is forgotten.
 */
class CvFiles
{
    public const DISK = 'local';

    public const LINK_MINUTES = 5;

    private const MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(private Place $place, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    /** The largest file taken, in KB. */
    public function maxKb(Organization $company): int
    {
        return (int) $this->rules->get('recruitment.cv_max_kb', $this->contexts->forOrganization($company));
    }

    /** A validation error on "cv" when the file is too big or not a CV kind (in the given language). */
    public function check(Organization $company, UploadedFile $file, ?string $locale = null): void
    {
        $maxKb = $this->maxKb($company);
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['cv' => __('recruitment::recruitment.validation.cv_size', ['max' => $maxKb], $locale)]);
        }
        if (! in_array((string) $file->getMimeType(), self::MIME_TYPES, true)) {
            throw ValidationException::withMessages(['cv' => __('recruitment::recruitment.validation.cv_kind', [], $locale)]);
        }
    }

    public function add(Organization $company, Application $application, UploadedFile $file, ?User $actor, ?string $locale = null): CandidateFile
    {
        $this->check($company, $file, $locale);
        $path = $file->storeAs("recruitment/{$company->getKey()}/{$application->getKey()}", Str::ulid().'.'.($file->guessExtension() ?? 'bin'), self::DISK);
        $kept = new CandidateFile;
        $kept->fill([
            'organization_id' => $company->getKey(), 'application_id' => $application->getKey(),
            'name' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'cv', 140, ''),
            'mime' => (string) $file->getMimeType(), 'size_bytes' => (int) $file->getSize(), 'path' => $path, 'uploaded_by' => $actor?->getKey(),
        ])->save();

        return $kept;
    }

    /** Every file of an application goes (the person is forgotten). */
    public function removeAll(Organization $company, Application $application): void
    {
        foreach ($this->place->query(CandidateFile::class, $company)->where('application_id', $application->getKey())->get() as $file) {
            Storage::disk(self::DISK)->delete($file->path);
            $file->delete();
        }
        Storage::disk(self::DISK)->deleteDirectory("recruitment/{$company->getKey()}/{$application->getKey()}");
    }

    /** A link to the file, good for a few minutes (relative: it works on any of the partner's hosts). */
    public function url(Organization $company, CandidateFile $file): string
    {
        return URL::temporarySignedRoute('recruitment.files.show', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $company->getKey(), 'file' => $file->getKey(),
        ], absolute: false);
    }
}
