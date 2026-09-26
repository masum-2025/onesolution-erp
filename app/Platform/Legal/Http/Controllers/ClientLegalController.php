<?php

namespace App\Platform\Legal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Legal\Exceptions\LegalException;
use App\Platform\Legal\Http\Requests\AcceptDocumentRequest;
use App\Platform\Legal\Models\DocumentAcceptance;
use App\Platform\Legal\Services\LegalService;
use App\Platform\Transfers\Http\Controllers\Concerns\ClientAccount;
use Illuminate\Http\JsonResponse;

/**
 * A legal document in force for the client, and accepting it (account
 * owners only). Everyone in the account may read it.
 */
class ClientLegalController extends Controller
{
    use ClientAccount;

    public function __construct(private LegalService $legal) {}

    public function show(string $organization, string $kind): JsonResponse
    {
        $root = $this->account($organization);
        $document = $this->legal->current($root->partner, $kind) ?? throw LegalException::noDocument();
        $accepted = $this->legal->accepted($root, $document);

        $history = DocumentAcceptance::query()->where('organization_id', $root->getKey())->where('kind', $kind)->orderByDesc('accepted_at')->limit(20)->get();
        $people = User::query()->whereKey($history->pluck('accepted_by')->unique()->values())->pluck('name', 'id');

        return response()->json(['data' => [
            'kind' => $document->kind,
            'version' => $document->version,
            'title' => $document->text('title'),
            'body' => $document->text('body'),
            'summary' => $document->summary,
            'published_at' => $document->published_at->toIso8601String(),
            'accepted_at' => $accepted?->accepted_at?->toIso8601String(),
            'history' => $history->map(fn (DocumentAcceptance $row) => [
                'version' => $row->version,
                'accepted_by' => $people[$row->accepted_by] ?? null,
                'accepted_at' => $row->accepted_at->toIso8601String(),
            ])->values(),
            'can_accept' => $this->ownsAccount($root),
        ]]);
    }

    public function accept(AcceptDocumentRequest $request, string $organization, string $kind): JsonResponse
    {
        $root = $this->account($organization);
        if (! $this->ownsAccount($root)) {
            throw LegalException::ownersOnly();
        }

        $acceptance = $this->legal->accept($root, $kind, (int) $request->validated('version'), $request->user(), app()->getLocale());

        return response()->json([
            'data' => ['accepted_at' => $acceptance->accepted_at->toIso8601String(), 'pending' => count($this->legal->pending($root))],
            'message' => __('legal.messages.accepted'),
        ]);
    }
}
