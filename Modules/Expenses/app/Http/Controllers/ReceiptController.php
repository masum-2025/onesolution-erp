<?php

namespace Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Expenses\Models\Receipt;
use Modules\Expenses\Services\Receipts;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A receipt, only through a link signed for a few minutes (made for the
 * people who may see the claim), shown in the browser, never kept by caches.
 */
class ReceiptController extends Controller
{
    public function show(string $organization, string $receipt): StreamedResponse
    {
        $found = Receipt::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization)->whereKey($receipt)->first();
        abort_if($found === null || ! Storage::disk(Receipts::DISK)->exists($found->path), 404);

        $name = (Str::slug($found->name) ?: 'receipt').'.'.pathinfo($found->path, PATHINFO_EXTENSION);

        return Storage::disk(Receipts::DISK)->response($found->path, $name, [
            'Content-Type' => $found->mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
