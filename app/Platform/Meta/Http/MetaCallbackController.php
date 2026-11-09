<?php

namespace App\Platform\Meta\Http;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use App\Platform\Meta\Events\MetaPersonRemovedApp;
use App\Platform\Meta\Events\MetaWebhookReceived;
use App\Platform\Meta\MetaApps;
use App\Platform\Meta\Models\MetaApp;
use App\Platform\Meta\Models\MetaDeletionRequest;
use App\Platform\Meta\SignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Meta's callbacks for one app (public, no sign-in; the signed_request is
 * the proof): a person removed the app (deauthorize: their pages stop
 * being used) or asked for their data to go (data deletion: the page
 * tokens are forgotten, and Meta gets a code to show them). Both are
 * required for Meta's app review.
 */
class MetaCallbackController extends Controller
{
    public function __construct(private MetaApps $apps, private SignedRequest $signed, private AuditLogger $audit) {}

    public function deauthorize(Request $request, string $app): JsonResponse
    {
        [$found, $person] = $this->read($request, $app);
        MetaPersonRemovedApp::dispatch($found->getKey(), $person, false);
        $this->audit->record(action: 'meta.person_removed_app', target: $found, partnerId: $found->partner_id);

        return response()->json(['ok' => true]);
    }

    public function dataDeletion(Request $request, string $app): JsonResponse
    {
        [$found, $person] = $this->read($request, $app);
        $event = new MetaPersonRemovedApp($found->getKey(), $person, true);
        event($event);

        $deletion = new MetaDeletionRequest;
        $deletion->meta_app_id = $found->getKey();
        $deletion->code = Str::lower((string) Str::ulid());
        $deletion->person_hash = hash('sha256', $found->getKey().'|'.$person);
        $deletion->pages_removed = $event->removed;
        $deletion->save();
        $this->audit->record(action: 'meta.data_deletion', target: $found, new: ['pages_removed' => $event->removed], partnerId: $found->partner_id);

        return response()->json([
            'url' => url('/api/meta/deletion/'.$deletion->code),
            'confirmation_code' => $deletion->code,
        ]);
    }

    /** Meta checks the webhook address: it must repeat the app's verify token; we answer its challenge. */
    public function verifyWebhook(Request $request, string $app): Response
    {
        $found = $this->apps->find($app);
        // PHP reads "hub.mode" in a query string as "hub_mode".
        $ok = $found !== null && $found->isActive() && $request->query('hub_mode') === 'subscribe'
            && is_string($found->webhook_verify_token) && hash_equals($found->webhook_verify_token, (string) $request->query('hub_verify_token', ''));
        abort_unless($ok, 403);

        return response((string) $request->query('hub_challenge', ''), 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * An event from Meta (comments, messages): only with the app's signature
     * (X-Hub-Signature-256 over the raw body). Handled on the queue by the
     * modules that hold the pages, so Meta gets its answer at once.
     */
    public function webhook(Request $request, string $app): JsonResponse
    {
        $found = $this->apps->find($app);
        abort_if($found === null || ! $found->isActive(), 404);
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $found->app_secret);
        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature-256', '')), 403);
        $payload = json_decode($request->getContent(), true);
        if (is_array($payload) && ($payload['object'] ?? null) === 'page') {
            MetaWebhookReceived::dispatch($found->getKey(), $payload);
        }

        return response()->json(['ok' => true]);
    }

    /** What a person who asked for deletion can look up with their code. */
    public function deletionStatus(string $code): JsonResponse
    {
        $deletion = MetaDeletionRequest::query()->where('code', $code)->first();
        abort_if($deletion === null, 404);

        return response()->json(['data' => [
            'confirmation_code' => $deletion->code,
            'status' => 'done',
            'message' => __('meta.deletion_done'),
            'received_at' => $deletion->created_at?->toIso8601String(),
        ]]);
    }

    /**
     * @return array{0: MetaApp, 1: string}
     */
    private function read(Request $request, string $app): array
    {
        $found = $this->apps->find($app);
        abort_if($found === null, 404);
        $payload = $this->signed->read($found, (string) $request->input('signed_request', ''));

        return [$found, (string) $payload['user_id']];
    }
}
