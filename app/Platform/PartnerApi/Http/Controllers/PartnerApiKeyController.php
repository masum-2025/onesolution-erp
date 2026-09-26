<?php

namespace App\Platform\PartnerApi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\PartnerApi\Exceptions\ApiException;
use App\Platform\PartnerApi\Http\Requests\ApiKeyRequest;
use App\Platform\PartnerApi\Models\PartnerApiKey;
use App\Platform\PartnerApi\Services\ApiKeyService;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner console: API keys for the partner's own systems. Owners only;
 * a key is shown once when it is made.
 */
class PartnerApiKeyController extends Controller
{
    use PartnerConsole;

    public function __construct(private ApiKeyService $keys, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function index(): JsonResponse
    {
        $this->requireOwner();
        $partner = $this->partner();
        $keys = PartnerApiKey::query()->where('partner_id', $partner->getKey())->latest()->get();
        $people = User::query()->whereKey($keys->pluck('created_by')->unique()->values())->pluck('name', 'id');

        return response()->json([
            'data' => $keys->map(fn (PartnerApiKey $key) => [
                'id' => $key->getKey(),
                'name' => $key->name,
                'prefix' => $key->prefix,
                'scopes' => $key->scopes,
                'status' => $key->status(),
                'created_by' => $people[$key->created_by] ?? null,
                'created_at' => $key->created_at?->toIso8601String(),
                'expires_at' => $key->expires_at->toIso8601String(),
                'last_used_at' => $key->last_used_at?->toIso8601String(),
            ])->values(),
            'scopes' => PartnerApiKey::SCOPES,
            'rate_per_minute' => (int) $this->rules->get('partners.api_rate_per_minute', $this->contexts->forPartner($partner)),
            'base_url' => url('/api/partner/v1'),
        ]);
    }

    public function store(ApiKeyRequest $request): JsonResponse
    {
        $this->requireOwner();
        $created = $this->keys->create($this->partner(), $request->validated('name'), $request->validated('scopes'), $request->user());

        return response()->json([
            // Shown once: only a hash is kept.
            'data' => ['key' => $created['key'], 'prefix' => $created['record']->prefix, 'expires_at' => $created['record']->expires_at->toIso8601String()],
            'message' => __('api.messages.key_created'),
        ], 201);
    }

    public function revoke(Request $request, string $key): JsonResponse
    {
        $this->requireOwner();
        $record = PartnerApiKey::query()->where('partner_id', $this->partner()->getKey())->whereKey($key)->first() ?? throw ApiException::notFound();
        $this->keys->revoke($record, $request->user());

        return response()->json(['message' => __('api.messages.key_revoked')]);
    }

    private function requireOwner(): void
    {
        if (! $this->hasRole(PartnerUserRole::Owner)) {
            throw ApiException::roleNotAllowed();
        }
    }
}
