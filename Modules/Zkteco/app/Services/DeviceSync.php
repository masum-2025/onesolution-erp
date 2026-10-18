<?php

namespace Modules\Zkteco\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Attendance\Contracts\DeviceAttendance;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Zkteco\Exceptions\ZktecoException;
use Modules\Zkteco\Models\Device;
use Modules\Zkteco\Models\DeviceUser;
use Modules\Zkteco\Models\Log;
use Modules\Zkteco\Protocol\TerminalFactory;
use RuntimeException;

/**
 * Bringing a terminal's punches into staff attendance.
 *
 * 1. Read the terminal: what it is, its users, its logs (reading only:
 *    nothing is written to it and its logs stay).
 * 2. Its users are kept and matched to HRM employees by code (the user id
 *    is the employee code); a match made by hand stays.
 * 3. Logs from the device's import_from day on are kept once each (device,
 *    user id, the terminal's time); its clock is read in the company's zone.
 * 4. Logs of matched users go to attendance (DeviceAttendance: each punch
 *    once, days worked out again); the others wait as unmatched, and go
 *    once their user is matched.
 */
class DeviceSync
{
    /** Logs kept and sent per round. */
    private const CHUNK = 500;

    public function __construct(
        private Place $place,
        private TerminalFactory $terminals,
        private EmployeeDirectory $employees,
        private DeviceAttendance $attendance,
    ) {}

    /**
     * Pull a device now. $onlyUser keeps one terminal user's logs alone (a first try on a real device).
     *
     * @return array{read: int, new: int, recorded: int, unmatched: int, not_employed: int, users: int}
     */
    public function pull(Organization $company, Device $device, ?User $actor, ?string $onlyUser = null): array
    {
        $terminal = $this->terminals->for($device);
        $device->forceFill(['last_attempt_at' => now()])->save();
        try {
            $terminal->connect();
            $facts = $terminal->facts();
            $users = $terminal->users();
            $logs = $terminal->attendance();
        } catch (RuntimeException $exception) {
            $device->forceFill(['last_error' => Devices::reason($exception)])->save();

            throw ZktecoException::unreachable(Devices::reason($exception));
        } finally {
            $terminal->disconnect();
        }

        return $this->ingest($company, $device, $facts, $users, $logs, $actor, $onlyUser);
    }

    /**
     * Keep what was read from a device, by the app itself (pull) or by an
     * office bridge (its readings): users, new logs, punches to attendance.
     *
     * @param  array{serial: ?string, model: ?string, users: int, logs: int}|null  $facts  null: unchanged (a bridge's later batch)
     * @param  list<array{uid: int, user_id: string, name: string}>  $users
     * @param  list<array{user_id: string, at: string, status: int, punch: int}>  $logs
     * @return array{read: int, new: int, recorded: int, unmatched: int, not_employed: int, users: int}
     */
    public function ingest(Organization $company, Device $device, ?array $facts, array $users, array $logs, ?User $actor, ?string $onlyUser = null): array
    {
        $this->keepUsers($company, $device, $users);
        $new = $this->keepLogs($company, $device, $logs, $onlyUser);
        $sent = $this->forward($company, $device, $actor);
        $device->forceFill([
            ...($facts === null ? [] : ['serial' => $facts['serial'], 'model' => $facts['model'], 'users_count' => $facts['users'], 'logs_count' => $facts['logs']]),
            'last_attempt_at' => now(), 'last_synced_at' => now(), 'last_error' => null,
        ])->save();

        return ['read' => count($logs), 'new' => $new, ...$sent, 'users' => count($users)];
    }

    /** The device's last kept log time (its own clock), from which a bridge sends; or the start of import_from. */
    public function keptUntil(Organization $company, Device $device): string
    {
        $last = $this->place->query(Log::class, $company)->where('device_id', $device->getKey())->max('local_at');

        return $last === null ? $device->import_from->toDateString().' 00:00:00' : substr((string) $last, 0, 19);
    }

    /**
     * Send waiting logs of matched users to attendance (after a pull, or after matching someone by hand).
     *
     * @return array{recorded: int, unmatched: int, not_employed: int}
     */
    public function forward(Organization $company, Device $device, ?User $actor): array
    {
        $matched = $this->place->query(DeviceUser::class, $company)->where('device_id', $device->getKey())->whereNotNull('employee_id')->pluck('employee_id', 'user_id');
        $counts = ['recorded' => 0, 'unmatched' => 0, 'not_employed' => 0];
        $this->place->query(Log::class, $company)->where('device_id', $device->getKey())->where('state', 'unmatched')
            ->orderBy('id')->chunkById(self::CHUNK, function ($logs) use ($company, $matched, $actor, &$counts) {
                $ready = $logs->filter(fn (Log $log) => isset($matched[$log->user_id]))->values();
                $counts['unmatched'] += $logs->count() - $ready->count();
                if ($ready->isEmpty()) {
                    return;
                }
                $results = $this->attendance->record($company, $ready->map(fn (Log $log) => [
                    'employee_id' => $matched[$log->user_id], 'at' => $log->punched_at,
                ])->all(), $actor);
                foreach ($ready as $index => $log) {
                    $state = match ($results[$index] ?? 'unknown') {
                        'added', 'already' => 'recorded',
                        'not_employed' => 'not_employed',
                        default => 'unmatched',
                    };
                    $counts[$state]++;
                    $log->forceFill(['state' => $state, 'employee_id' => $state === 'unmatched' ? null : $matched[$log->user_id]])->save();
                }
            });

        return $counts;
    }

    /**
     * Keep the terminal's users; match new ones (and those still unmatched) by employee code.
     *
     * @param  list<array{uid: int, user_id: string, name: string}>  $users
     */
    private function keepUsers(Organization $company, Device $device, array $users): void
    {
        $kept = $this->place->query(DeviceUser::class, $company)->where('device_id', $device->getKey())->get()->keyBy('user_id');
        $byCode = $this->employees->byCodes($company, array_column($users, 'user_id'));
        foreach ($users as $user) {
            $row = $kept[$user['user_id']] ?? new DeviceUser(['organization_id' => $company->getKey(), 'device_id' => $device->getKey(), 'user_id' => $user['user_id']]);
            $row->fill(['device_uid' => $user['uid'], 'name' => mb_substr($user['name'], 0, 40) ?: null]);
            if ($row->matched_by !== 'hand' && $row->employee_id === null && isset($byCode[$user['user_id']])) {
                $row->fill(['employee_id' => $byCode[$user['user_id']]->id, 'matched_by' => 'code']);
            }
            $row->save();
        }
    }

    /**
     * Keep logs from import_from on that are not kept yet; how many were new.
     *
     * @param  list<array{user_id: string, at: string, status: int, punch: int}>  $logs
     */
    private function keepLogs(Organization $company, Device $device, array $logs, ?string $onlyUser): int
    {
        $from = $device->import_from->toDateString();
        $zone = $this->place->timezone($company);
        $logs = array_values(array_filter($logs, fn (array $log) => substr($log['at'], 0, 10) >= $from && ($onlyUser === null || $log['user_id'] === $onlyUser)));
        if ($logs === []) {
            return 0;
        }
        $seen = $this->place->query(Log::class, $company)->where('device_id', $device->getKey())->where('local_at', '>=', "{$from} 00:00:00")
            ->get(['user_id', 'local_at'])->mapWithKeys(fn (Log $log) => ["{$log->user_id}|".substr((string) $log->getRawOriginal('local_at'), 0, 19) => true])->all();
        $now = now();
        $rows = [];
        foreach ($logs as $log) {
            $key = "{$log['user_id']}|{$log['at']}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = [
                'id' => (string) Str::ulid(), 'organization_id' => $company->getKey(), 'device_id' => $device->getKey(), 'user_id' => mb_substr($log['user_id'], 0, 24),
                'local_at' => $log['at'], 'punched_at' => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $log['at'], $zone)->utc()->format('Y-m-d H:i:s'),
                'status' => $log['status'], 'punch' => $log['punch'], 'state' => 'unmatched', 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            // No tenant context when scheduled: the company is named on every row.
            Log::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->insert($chunk);
        }

        return count($rows);
    }
}
