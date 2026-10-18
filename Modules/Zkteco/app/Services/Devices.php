<?php

namespace Modules\Zkteco\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Zkteco\Exceptions\ZktecoException;
use Modules\Zkteco\Models\Device;
use Modules\Zkteco\Models\Log;
use Modules\Zkteco\Protocol\TerminalFactory;
use RuntimeException;

/**
 * Keeping terminals: add, change (with their version), switch off or
 * remove one that brought no logs; try one (what it says about itself).
 * Changes are audited; the comm key is never written into the audit.
 */
class Devices
{
    public function __construct(private Place $place, private TerminalFactory $terminals, private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function save(Organization $company, ?Device $device, array $data, User $actor): Device
    {
        return $this->place->transaction($company, function () use ($company, $device, $data, $actor) {
            if ($device !== null) {
                $device = $this->place->query(Device::class, $company)->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
                if ($device->version !== (int) $data['base_version']) {
                    throw ZktecoException::versionConflict(['version' => $device->version]);
                }
                $old = $this->facts($device);
                $device->fill(array_intersect_key($data, array_flip(['name', 'host', 'port', 'protocol', 'import_from', 'is_active'])));
                $device->version++;
            } else {
                $old = [];
                $device = new Device(['organization_id' => $company->getKey(), ...array_intersect_key($data, array_flip(['unit_id', 'name', 'host', 'port', 'protocol', 'import_from', 'is_active']))]);
            }
            $taken = $this->place->query(Device::class, $company)->where('host', $device->host)->where('port', $device->port)
                ->when($device->exists, fn ($query) => $query->whereKeyNot($device->getKey()))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['host' => __('zkteco::zkteco.validation.host_taken')]);
            }
            $keyChanged = array_key_exists('comm_key', $data);
            if ($keyChanged) {
                $device->comm_key = $data['comm_key'] === null || $data['comm_key'] === '' ? null : (string) $data['comm_key'];
            }
            $device->save();
            $this->audit->record($old === [] ? 'zkteco.device_added' : 'zkteco.device_changed', $device,
                old: $old, new: [...$this->facts($device), ...($keyChanged ? ['comm_key' => 'changed'] : [])], actor: $actor, organizationId: $company->getKey());

            return $device;
        });
    }

    /** Remove a device that brought no logs; one that did is switched off instead (409 says so). */
    public function remove(Organization $company, Device $device, int $baseVersion, User $actor): void
    {
        $this->place->transaction($company, function () use ($company, $device, $baseVersion, $actor) {
            $device = $this->place->query(Device::class, $company)->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            if ($device->version !== $baseVersion) {
                throw ZktecoException::versionConflict(['version' => $device->version]);
            }
            if ($this->place->query(Log::class, $company)->where('device_id', $device->getKey())->exists()) {
                throw ZktecoException::hasLogs();
            }
            $this->audit->record('zkteco.device_removed', $device, old: $this->facts($device), actor: $actor, organizationId: $company->getKey());
            $device->delete();
        });
    }

    /**
     * Reach the terminal and ask what it is (nothing brought in); kept on the device.
     *
     * @return array{serial: ?string, model: ?string, users: int, logs: int}
     */
    public function test(Organization $company, Device $device): array
    {
        $terminal = $this->terminals->for($device);
        try {
            $terminal->connect();
            $facts = $terminal->facts();
        } catch (RuntimeException $exception) {
            $device->forceFill(['last_attempt_at' => now(), 'last_error' => self::reason($exception)])->save();

            throw ZktecoException::unreachable(self::reason($exception));
        } finally {
            $terminal->disconnect();
        }
        $device->forceFill(['serial' => $facts['serial'], 'model' => $facts['model'], 'users_count' => $facts['users'], 'logs_count' => $facts['logs'], 'last_attempt_at' => now(), 'last_error' => null])->save();

        return $facts;
    }

    /** Why a terminal could not be read, as a code people's words come from. */
    public static function reason(RuntimeException $exception): string
    {
        $code = strtok($exception->getMessage(), ':');

        return match ($code) {
            'wrong_comm_key' => 'wrong_comm_key',
            'connect_refused' => 'refused',
            'read_failed' => 'read_failed',
            default => 'no_answer',
        };
    }

    /** @return array<string, mixed> */
    private function facts(Device $device): array
    {
        return ['unit_id' => $device->unit_id, 'name' => $device->name, 'host' => $device->host, 'port' => $device->port, 'protocol' => $device->protocol,
            'import_from' => $device->import_from?->toDateString(), 'is_active' => $device->is_active];
    }
}
