<?php

namespace Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Recruitment\Models\CandidateFile;
use Modules\Recruitment\Services\CvFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CV, only through a link signed for a few minutes (made for the people
 * who may see the application): a PDF shown in the browser, a Word file
 * downloaded; never kept by caches.
 */
class FileController extends Controller
{
    public function show(string $organization, string $file): StreamedResponse
    {
        $found = CandidateFile::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization)->whereKey($file)->first();
        abort_if($found === null || ! Storage::disk(CvFiles::DISK)->exists($found->path), 404);

        $name = (Str::slug($found->name) ?: 'cv').'.'.pathinfo($found->path, PATHINFO_EXTENSION);

        return Storage::disk(CvFiles::DISK)->response($found->path, $name, [
            'Content-Type' => $found->mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ], $found->mime === 'application/pdf' ? 'inline' : 'attachment');
    }
}
