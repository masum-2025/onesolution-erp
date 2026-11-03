<?php

namespace App\Platform\PageExports\Http;

use App\Http\Controllers\Controller;
use App\Platform\PageExports\PageExports;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a module's pages may export or print here (the buttons shown), and
 * one export or print checked and audited before the browser builds it.
 */
class PageExportController extends Controller
{
    public function __construct(private PageExports $exports, private CurrentContext $context) {}

    public function show(Request $request): JsonResponse
    {
        $request->validate(['module' => ['required', 'string', 'regex:/^(?:core\.)?[a-z][a-z0-9_]{1,49}$/']]);

        return response()->json(['data' => $this->exports->settings($this->context->organization(), $request->string('module')->toString())]);
    }

    public function store(PageExportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->exports->record($this->context->organization(), $request->validated(), $request->user())], 201);
    }
}
