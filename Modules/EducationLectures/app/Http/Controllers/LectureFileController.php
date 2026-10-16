<?php

namespace Modules\EducationLectures\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Storage;
use Modules\EducationLectures\Models\Lecture;
use Modules\EducationLectures\Models\LectureFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A lecture's file through a short-lived signed link (routes/web.php; the
 * signature, made only after the person's access was checked, is the
 * permission). A file of a withdrawn lecture no longer opens.
 */
class LectureFileController extends Controller
{
    public function download(string $organization, string $file): StreamedResponse
    {
        $found = LectureFile::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization)->whereKey($file)->first();
        $lecture = $found === null ? null : Lecture::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization)->whereKey($found->lecture_id)->first();

        abort_if($found === null || $lecture === null || $lecture->status === 'withdrawn' || ! Storage::disk('local')->exists($found->path), 404);

        return Storage::disk('local')->download($found->path, $found->name, [
            'Content-Type' => $found->mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
