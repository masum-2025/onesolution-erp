<?php

namespace App\Platform\Connections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Connections\Exceptions\ConnectionException;
use App\Platform\Connections\Http\Requests\CodeRequest;
use App\Platform\Connections\Http\Requests\InviteRequest;
use App\Platform\Connections\Http\Requests\StepRequest;
use App\Platform\Connections\Http\Requests\UnitsRequest;
use App\Platform\Connections\Models\Connection;
use App\Platform\Connections\Models\ConnectionMessage;
use App\Platform\Connections\Services\ConnectionLinks;
use App\Platform\Connections\Services\Connections;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Business connections (NET-1) at the organization in the address: its
 * clients (when it is, or is under, the provider company) and its service
 * providers (when it is, or is under, the client). Seeing needs
 * business_network.view; acting needs business_network.manage at the side
 * itself. Only names cross between the two sides here, never their data.
 */
class ConnectionController extends Controller
{
    use FindsVisibleOrganizations;

    private const STEPS = ['pause', 'resume', 'end'];

    public function __construct(private ConnectionLinks $links, private Connections $connections, private CurrentContext $context) {}

    public function index(string $organization): JsonResponse
    {
        $organization = $this->organization($organization, 'business_network.view');
        $chain = [...$organization->ancestorIds(), $organization->getKey()];

        return response()->json([
            'data' => $this->links->visibleTo($organization)->map(fn (Connection $connection) => $this->item($connection, $chain, $organization))->values(),
            'meta' => [
                // Only a company invites clients; a group or a company accepts.
                'can_invite' => $organization->type->value === 'company' && Gate::allows('business_network.manage', $organization),
                'can_accept' => in_array($organization->type->value, ['group', 'company'], true) && Gate::allows('business_network.manage', $organization),
            ],
        ]);
    }

    public function invite(InviteRequest $request, string $organization): JsonResponse
    {
        $organization = $this->organization($organization, 'business_network.manage');
        $made = $this->links->invite($organization, $request->validated('note'), $request->user());

        return response()->json(['data' => [
            ...$this->item($made['connection'], [$organization->getKey()], $organization),
            // Shown this once; only its hash is kept.
            'code' => $made['code'],
        ]], 201);
    }

    /** Who invites, before accepting, and the units here the provider could be shown. */
    public function preview(CodeRequest $request, string $organization): JsonResponse
    {
        $organization = $this->organization($organization, 'business_network.manage');
        $connection = $this->links->byCode($request->validated('code'));

        return response()->json(['data' => [
            'provider' => $connection->provider?->displayName(),
            'expires_at' => $connection->expires_at?->toIso8601String(),
            'units' => $this->ownUnits($organization),
        ]]);
    }

    public function accept(CodeRequest $request, string $organization): JsonResponse
    {
        $organization = $this->organization($organization, 'business_network.manage');
        $connection = $this->links->accept($request->validated('code'), $organization, $request->validated('unit_ids'), $request->user());

        return response()->json(['data' => $this->detail($connection->fresh(['provider', 'client']), $organization)], 201);
    }

    public function show(string $organization, string $connection): JsonResponse
    {
        $organization = $this->organization($organization, 'business_network.view');

        return response()->json(['data' => $this->detail($this->connectionIn($organization, $connection), $organization)]);
    }

    public function units(UnitsRequest $request, string $organization, string $connection): JsonResponse
    {
        $organization = $this->organization($organization, 'business_network.manage');
        $found = $this->connectionIn($organization, $connection);
        $changed = $this->links->setUnits($found, $organization, $request->validated('unit_ids'), (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->detail($changed->fresh(['provider', 'client']), $organization)]);
    }

    public function step(StepRequest $request, string $organization, string $connection, string $step): JsonResponse
    {
        $organization = $this->organization($organization, 'business_network.manage');
        abort_unless(in_array($step, self::STEPS, true), 404);
        $found = $this->connectionIn($organization, $connection);
        $done = $this->links->step($found, $organization, $step, (int) $request->validated('base_version'), $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->detail($done->fresh(['provider', 'client']), $organization)]);
    }

    /** What was sent each way: envelopes only (kind, who, when, delivered), never what was in them. */
    public function messages(string $organization, string $connection): JsonResponse
    {
        $organization = $this->organization($organization, 'business_network.view');
        $found = $this->connectionIn($organization, $connection);
        $names = [];
        $name = function (string $id) use (&$names) {
            return $names[$id] ??= (string) Organization::query()->find($id)?->displayName();
        };
        $mine = $this->sideFor($found, [...$organization->ancestorIds(), $organization->getKey()]);

        $messages = ConnectionMessage::query()->where('connection_id', $found->getKey())->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $messages->map(fn (ConnectionMessage $message) => [
            'id' => $message->getKey(),
            'kind' => $message->kind,
            'sent' => ($message->direction === ConnectionMessage::TO_CLIENT) === ($mine === Connection::PROVIDER),
            'from' => $name($message->from_unit_id),
            'to' => $name($message->to_unit_id),
            'status' => $message->status,
            'error' => $message->error === null ? null : __("connections.delivery_errors.{$message->error}"),
            'created_at' => $message->created_at?->toIso8601String(),
            'delivered_at' => $message->delivered_at?->toIso8601String(),
        ])->values()]);
    }

    private function organization(string $id, string $permission): Organization
    {
        if ($this->context->hasOrganization() && $this->context->isSupport()) {
            throw ConnectionException::notDuringSupport();
        }
        $organization = $this->findVisible($id);
        Gate::authorize($permission, $organization);

        return $organization;
    }

    /** A connection the organization (or a unit above it) is a side of; otherwise not found. */
    private function connectionIn(Organization $organization, string $id): Connection
    {
        $connection = Connection::query()->with(['provider', 'client'])->find($id);
        $chain = [...$organization->ancestorIds(), $organization->getKey()];
        if ($connection === null || $this->sideFor($connection, $chain) === null) {
            throw ConnectionException::notFound();
        }

        return $connection;
    }

    /**
     * @param  list<string>  $chain
     */
    private function sideFor(Connection $connection, array $chain): ?string
    {
        return match (true) {
            in_array($connection->provider_id, $chain, true) => Connection::PROVIDER,
            $connection->client_id !== null && in_array($connection->client_id, $chain, true) => Connection::CLIENT,
            default => null,
        };
    }

    /**
     * @param  list<string>  $chain
     * @return array<string, mixed>
     */
    private function item(Connection $connection, array $chain, Organization $organization): array
    {
        $side = $this->sideFor($connection, $chain);
        $other = $side === Connection::PROVIDER ? $connection->client : $connection->provider;
        // Acting is for the side itself (the provider company, the client group or company), not a unit under it.
        $acts = $connection->sideOf($organization->getKey()) !== null && Gate::allows('business_network.manage', $organization);

        return [
            'id' => $connection->getKey(),
            'side' => $side,
            'status' => $connection->status,
            'other' => $other?->displayName(),
            // The provider's own label for whom it invited: never shown to the client.
            'note' => $side === Connection::PROVIDER ? $connection->note : null,
            'expires_at' => $connection->status === Connection::INVITED ? $connection->expires_at?->toIso8601String() : null,
            'accepted_at' => $connection->accepted_at?->toIso8601String(),
            'paused_by' => $connection->paused_by_side,
            'ended_at' => $connection->ended_at?->toIso8601String(),
            'version' => $connection->version,
            'can' => [
                'units' => $acts && $side === Connection::CLIENT && in_array($connection->status, [Connection::ACTIVE, Connection::PAUSED], true),
                'pause' => $acts && $connection->status === Connection::ACTIVE,
                'resume' => $acts && $connection->status === Connection::PAUSED && $connection->paused_by_side === $side,
                'end' => $acts && $connection->status !== Connection::ENDED,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Connection $connection, Organization $organization): array
    {
        $chain = [...$organization->ancestorIds(), $organization->getKey()];
        $item = $this->item($connection, $chain, $organization);
        $shared = $this->links->activeUnits($connection);

        return [
            ...$item,
            'end_reason' => $connection->end_reason,
            // The shared units and what they cover, by name: all the provider ever learns of the client.
            'units' => $connection->client_id === null ? [] : $this->connections->unitsFor($connection),
            'shared_ids' => $shared->pluck('unit_id')->values(),
            // The client's own companies and branches, to change what is shared.
            'own_units' => $item['can']['units'] ? $this->ownUnits($organization) : [],
        ];
    }

    /**
     * @return list<array{id: string, type: string, name: string, depth: int, parent_id: string|null}>
     */
    private function ownUnits(Organization $organization): array
    {
        return Organization::query()->subtreeOf($organization)->whereIn('type', ['company', 'branch'])
            // By path: each branch right under its own company (a tree), never all companies first.
            ->where('status', OrganizationStatus::Active->value)->orderBy('path')->get()
            ->map(fn (Organization $unit) => [
                'id' => $unit->getKey(),
                'type' => $unit->type->value,
                'name' => $unit->displayName(),
                'depth' => $unit->depth,
                'parent_id' => $unit->parent_id,
            ])->values()->all();
    }
}
