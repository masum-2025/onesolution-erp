<?php

namespace App\Platform\Meta\Http;

use App\Http\Controllers\Controller;
use App\Platform\Meta\MetaApps;
use App\Platform\Meta\Models\MetaApp;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;

/**
 * Partner console: the partner's own Meta app. Partner owners see and
 * change it; a change waits for a second owner. The secret never leaves
 * the server: only its last four characters are shown.
 */
class PartnerMetaAppController extends Controller
{
    use PartnerConsole;

    public function __construct(private MetaApps $apps) {}

    public function show(): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);

        return response()->json(['data' => $this->payload($this->apps->of($this->partner()))]);
    }

    public function update(MetaAppRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $data = $request->validated();
        $app = $this->apps->change($this->partner(), $request->user(), $data['password'], isset($data['base_version']) ? (int) $data['base_version'] : null, $data['app_id'], $data['app_secret']);

        return response()->json(['data' => $this->payload($app), 'message' => __($app->activates_at !== null ? 'meta.messages.waits' : 'meta.messages.needs_approval')]);
    }

    public function approve(MetaAppRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $app = $this->apps->approve($this->partner(), $request->user(), $request->validated('password'), (int) $request->validated('base_version'));

        return response()->json(['data' => $this->payload($app), 'message' => __('meta.messages.approved')]);
    }

    public function reject(MetaAppRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $app = $this->apps->reject($this->partner(), $request->user(), (int) $request->validated('base_version'));

        return response()->json(['data' => $this->payload($app), 'message' => __('meta.messages.rejected')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?MetaApp $app): array
    {
        $user = request()->user();

        return [
            'exists' => $app !== null,
            'status' => $app?->status,
            'app_id' => $app?->app_id,
            'app_name' => $app?->app_name,
            'secret_hint' => $app?->app_secret === null ? null : '••••'.substr((string) $app->app_secret, -4),
            'approved_at' => $app?->approved_at?->toIso8601String(),
            'pending' => $app?->hasPendingChange() ? [
                'app_id' => $app->pending_app_id,
                'app_name' => $app->pending_app_name,
                'at' => $app->pending_at?->toIso8601String(),
                'activates_at' => $app->activates_at?->toIso8601String(),
                'mine' => $app->pending_by === $user?->getKey(),
            ] : null,
            'version' => $app?->version,
            // What to enter at developers.facebook.com for this app.
            'callbacks' => $app === null ? null : [
                'redirect_uri' => url((string) config('meta.redirect_path')),
                'deauthorize' => url("/api/meta/{$app->getKey()}/deauthorize"),
                'data_deletion' => url("/api/meta/{$app->getKey()}/data-deletion"),
                // Webhooks → Page: callback URL and verify token; fields feed and messages.
                'webhook' => url("/api/meta/{$app->getKey()}/webhook"),
                'verify_token' => $app->webhook_verify_token,
            ],
            'second_owner' => $this->apps->approvers($this->partner(), $user)->isNotEmpty(),
            'wait_hours' => (int) config('meta.single_approver_wait_hours'),
        ];
    }
}
