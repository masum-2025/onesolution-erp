<?php

namespace Modules\Compliance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\Models\Connection;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Models\AuditType;
use Modules\Compliance\Models\Provider;
use Modules\Compliance\Models\Service;

/**
 * The register's lists: audit services, kinds of audit and providers. A
 * group keeps them for all its companies (a company may add its own); they
 * are switched off, never removed. "The usual ones" come from the rules
 * compliance.default_services / default_audit_types (per country).
 */
class Lists
{
    public const KINDS = ['services' => Service::class, 'types' => AuditType::class, 'providers' => Provider::class];

    public function __construct(private Place $place, private RuleResolver $rules, private RuleContextFactory $contexts, private AuditLogger $audit) {}

    /**
     * @return Collection<int, Model>
     */
    public function of(Organization $unit, string $kind, bool $all = false): Collection
    {
        $model = self::KINDS[$kind];

        return $this->place->query($model, $unit, $this->place->listOwners($unit))
            ->when(! $all, fn ($query) => $query->where('is_active', true))
            ->get()
            ->sortBy(fn (Model $item) => $kind === 'providers' ? mb_strtolower($item->name) : sprintf('%05d %s', $item->sort_order, $item->code))
            ->values();
    }

    public function find(Organization $unit, string $kind, string $id): Model
    {
        return $this->place->query(self::KINDS[$kind], $unit, $this->place->listOwners($unit))->whereKey($id)->first()
            ?? throw ComplianceException::itemNotFound();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Organization $owner, string $kind, ?Model $item, array $data, User $actor): Model
    {
        if (! in_array($owner->type, [OrganizationType::Group, OrganizationType::Company], true)) {
            throw ComplianceException::notListOwner();
        }
        if ($item !== null && $item->organization_id !== $owner->getKey()) {
            // Kept by a unit above: changed there, not here.
            throw ComplianceException::notListOwner();
        }

        return $this->place->transaction($owner, function () use ($owner, $kind, $item, $data, $actor) {
            $model = self::KINDS[$kind];
            $new = $item === null;
            if (! $new) {
                $item = $this->place->query($model, $owner, [$owner->getKey()])->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
                if ($item->version !== (int) $data['base_version']) {
                    throw ComplianceException::versionConflict();
                }
            }
            $item ??= new $model(['organization_id' => $owner->getKey(), 'version' => 0]);
            $old = $new ? [] : $this->values($kind, $item);

            if ($kind === 'providers') {
                $item->fill(array_intersect_key($data, array_flip(['name', 'email', 'phone', 'is_active'])));
                if (array_key_exists('connection_id', $data)) {
                    $connection = $data['connection_id'] === null ? null : $this->connectionsOf($owner)->firstWhere('id', $data['connection_id']);
                    if ($data['connection_id'] !== null && $connection === null) {
                        throw ComplianceException::connectionNotYours();
                    }
                    $item->connection_id = $connection['id'] ?? null;
                }
            } else {
                if (array_key_exists('code', $data) && $this->codeTaken($owner, $kind, (string) $data['code'], $item->getKey())) {
                    throw ComplianceException::codeTaken();
                }
                $item->fill(array_intersect_key($data, array_flip(['code', 'is_active', 'sort_order', ...($kind === 'services' ? ['validity_months'] : [])])));
                if (isset($data['name'])) {
                    $item->putTexts('name', $data['name']);
                }
            }
            $item->version = ($item->version ?? 0) + 1;
            $item->save();

            $this->audit->record($new ? "compliance.{$kind}_added" : "compliance.{$kind}_changed", $item, old: $old, new: $this->values($kind, $item),
                actor: $actor, organizationId: $owner->getKey());

            return $item;
        });
    }

    /**
     * Business connections a provider of the lists may be linked to: where the
     * owner (or a unit above it) is the client and the connection is not ended.
     * Only the provider's name crosses over.
     *
     * @return Collection<int, array{id: string, name: string, status: string}>
     */
    public function connectionsOf(Organization $owner): Collection
    {
        return Connection::query()->with('provider')->whereIn('client_id', $this->place->listOwners($owner))
            ->whereIn('status', [Connection::ACTIVE, Connection::PAUSED])->get()
            ->map(fn (Connection $connection) => ['id' => $connection->getKey(), 'name' => (string) $connection->provider?->displayName(), 'status' => $connection->status])
            ->sortBy('name')->values();
    }

    /** The usual services or kinds of audit (from the rules), those not there yet. Returns how many were added. */
    public function addDefaults(Organization $owner, string $kind, User $actor): int
    {
        $rule = $kind === 'services' ? 'compliance.default_services' : 'compliance.default_audit_types';
        $defaults = (array) $this->rules->get($rule, $this->contexts->forOrganization($owner));
        $added = 0;
        foreach (array_values($defaults) as $index => $default) {
            if ($this->codeTaken($owner, $kind, (string) $default['code'], null)) {
                continue;
            }
            $this->save($owner, $kind, null, [
                'code' => $default['code'],
                'name' => $default['name'],
                'sort_order' => ($index + 1) * 10,
                ...($kind === 'services' ? ['validity_months' => $default['validity_months'] ?? null] : []),
            ], $actor);
            $added++;
        }

        return $added;
    }

    private function codeTaken(Organization $owner, string $kind, string $code, ?string $except): bool
    {
        return $this->place->query(self::KINDS[$kind], $owner, $this->place->listOwners($owner))
            ->where('code', $code)->when($except !== null, fn ($query) => $query->whereKeyNot($except))->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function values(string $kind, Model $item): array
    {
        return $kind === 'providers'
            ? $item->only(['name', 'email', 'phone', 'is_active', 'connection_id'])
            : [...$item->only(['code', 'is_active', ...($kind === 'services' ? ['validity_months'] : [])]), 'name' => $item->texts('name')];
    }
}
