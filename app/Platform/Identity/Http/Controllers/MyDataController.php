<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Exceptions\IdentityException;
use App\Platform\Identity\Http\Requests\DeletionRequest;
use App\Platform\Identity\Services\AccountDeletion;
use App\Platform\Identity\Services\PersonalDataExport;
use App\Platform\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The person's own data (Phase 5C-3): download it, or ask for the account to
 * be deleted after a grace period (and change their mind until then).
 */
class MyDataController extends Controller
{
    public function __construct(private AccountDeletion $deletion, private AuditLogger $audit) {}

    public function download(Request $request, PersonalDataExport $export): Response
    {
        $user = $request->user();
        $this->audit->record(action: 'identity.data_downloaded', target: $user, actor: $user);

        $json = json_encode($export->build($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="my-data-'.now()->format('Y-m-d').'.json"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function requestDeletion(DeletionRequest $request): JsonResponse
    {
        // The word DELETE, typed on purpose (any letter case).
        if (mb_strtoupper(trim((string) $request->validated('confirm'))) !== 'DELETE') {
            throw IdentityException::deletionConfirmMissing();
        }

        $user = $this->deletion->request($request->user(), $request->validated('current_password'));

        return response()->json([
            'data' => $this->state($user),
            'message' => __('identity.messages.deletion_requested', ['date' => LocalDate::format($user->deletion_due_at)]),
        ]);
    }

    public function cancelDeletion(Request $request): JsonResponse
    {
        $user = $this->deletion->cancel($request->user());

        return response()->json(['data' => $this->state($user), 'message' => __('identity.messages.deletion_cancelled')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(User $user): array
    {
        return [
            'deletion_due_at' => $user->deletion_due_at?->toIso8601String(),
            'deletion_blockers' => $this->deletion->describe($this->deletion->blockers($user)),
        ];
    }
}
