<?php

namespace App\Platform\Transfers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Legal\Models\LegalDocument;
use App\Platform\Legal\Services\LegalService;
use App\Platform\Transfers\Exceptions\TransferException;
use App\Platform\Transfers\Http\Controllers\Concerns\ClientAccount;
use App\Platform\Transfers\Http\Requests\TransferRequest;
use App\Platform\Transfers\Http\TransferPresenter;
use App\Platform\Transfers\Models\ClientTransfer;
use App\Platform\Transfers\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The client's own view of its provider: who it is, the legal documents in
 * force and whether they were accepted, and moving to another provider
 * (account owners only).
 */
class ClientProviderController extends Controller
{
    use ClientAccount;

    public function __construct(
        private TransferService $transfers,
        private TransferPresenter $presenter,
        private LegalService $legal,
        private BrandResolver $brands,
    ) {}

    public function show(string $organization): JsonResponse
    {
        $root = $this->account($organization);
        $partner = $root->partner;
        $brand = $this->brands->for($partner);
        $owner = $this->ownsAccount($root);

        $documents = [];
        foreach (LegalDocument::KINDS as $kind) {
            $document = $this->legal->current($partner, $kind);
            if ($document === null) {
                continue;
            }

            $accepted = $this->legal->accepted($root, $document);
            $documents[] = [
                'kind' => $kind,
                'version' => $document->version,
                'title' => $document->text('title'),
                'summary' => $document->summary,
                'published_at' => $document->published_at->toIso8601String(),
                'from_partner' => $document->partner_id !== null,
                'needs_acceptance' => in_array($kind, LegalDocument::ACCEPTED_KINDS, true),
                'accepted_at' => $accepted?->accepted_at?->toIso8601String(),
            ];
        }

        $transfer = ClientTransfer::query()->where('organization_id', $root->getKey())->latest()->first();

        return response()->json(['data' => [
            'organization' => ['id' => $root->getKey(), 'name' => $root->displayName()],
            'provider' => [
                'name' => $partner->name,
                'product' => $brand['name'],
                'support_email' => $brand['support_email'],
                'support_phone' => $brand['support_phone'],
                'house' => (bool) $partner->is_house,
            ],
            'documents' => $documents,
            'pending' => count($this->legal->pending($root)),
            // Moving is the account owner's decision; others only see that it happened.
            'transfer' => $transfer === null ? null : ($owner ? $this->presenter->transfer($transfer) : ['status' => $transfer->status]),
            'can_accept' => $owner,
            'can_transfer' => $owner,
        ]]);
    }

    public function preview(TransferRequest $request, string $organization): JsonResponse
    {
        $root = $this->account($organization);
        $this->requireAccountOwner($root);

        $to = $this->transfers->destination($root, $request->validated('code'), $request->toHouse());

        return response()->json(['data' => $this->transfers->preview($root, $to)]);
    }

    public function store(TransferRequest $request, string $organization): JsonResponse
    {
        $root = $this->account($organization);
        $this->requireAccountOwner($root);

        $transfer = $this->transfers->request($root, $request->validated('code'), $request->toHouse(), $request->validated('reason'), $request->user());

        return response()->json([
            'data' => $this->presenter->transfer($transfer),
            'message' => __($transfer->status === ClientTransfer::COMPLETED ? 'transfers.messages.completed' : 'transfers.messages.requested', ['partner' => $transfer->toPartner->name]),
        ], 201);
    }

    public function cancel(Request $request, string $organization, string $transfer): JsonResponse
    {
        $root = $this->account($organization);
        $this->requireAccountOwner($root);

        $record = ClientTransfer::query()->where('organization_id', $root->getKey())->whereKey($transfer)->first()
            ?? throw TransferException::notFound();

        return response()->json(['data' => $this->presenter->transfer($this->transfers->cancel($record, $request->user())), 'message' => __('transfers.messages.cancelled')]);
    }
}
