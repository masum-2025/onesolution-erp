<?php

namespace App\Platform\PageExports\Http;

use App\Http\Controllers\Controller;
use App\Platform\PageExports\PageExports;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Export and print on partner console pages: what a page may offer to this
 * partner person (by role and the partner's rules), and one export checked
 * and audited on the partner. The partner comes from the context only.
 */
class PartnerPageExportController extends Controller
{
    use PartnerConsole;

    public function __construct(private PageExports $exports, private CurrentContext $context) {}

    public function show(Request $request): JsonResponse
    {
        $request->validate(['page' => ['required', 'string', Rule::in(array_keys(PageExports::PARTNER_PAGES))]]);

        return response()->json(['data' => $this->exports->partnerSettings($this->partner(), $this->context->partnerUser()->role, $request->string('page')->toString())]);
    }

    public function store(PartnerPageExportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->exports->recordForPartner($this->partner(), $this->context->partnerUser()->role, $request->validated(), $request->user())], 201);
    }
}
