<?php

namespace Modules\Zkteco\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Zkteco\Models\Device;
use Modules\Zkteco\Models\DeviceUser;

/**
 * Matching a terminal's user to an HRM employee by hand (or to nobody), when
 * the user id is not the employee code. Audited. The user's waiting logs go
 * to attendance right away; logs already recorded stay with whom they went
 * to (a wrong punch is voided in attendance).
 */
class Matching
{
    public function __construct(
        private Place $place,
        private EmployeeDirectory $employees,
        private DeviceSync $sync,
        private AuditLogger $audit,
    ) {}

    /** @return array{user: DeviceUser, recorded: int} */
    public function match(Organization $company, Device $device, DeviceUser $user, ?string $employeeId, User $actor): array
    {
        $recorded = $this->matchMany($company, $device, [[$user, $employeeId]], $actor);

        return ['user' => $user, 'recorded' => $recorded];
    }

    /**
     * Match several users at once (the "accept all suggestions" button); their waiting logs go once at the end.
     *
     * @param  list<array{0: DeviceUser, 1: ?string}>  $pairs
     * @return int Punches recorded.
     */
    public function matchMany(Organization $company, Device $device, array $pairs, User $actor): int
    {
        $ids = array_values(array_filter(array_column($pairs, 1)));
        $known = $this->employees->many($company, $ids);
        if (count(array_diff($ids, array_keys($known))) > 0) {
            throw ValidationException::withMessages(['employee_id' => __('zkteco::zkteco.validation.employee')]);
        }
        foreach ($pairs as [$user, $employeeId]) {
            $old = ['employee_id' => $user->employee_id, 'matched_by' => $user->matched_by];
            $user->fill(['employee_id' => $employeeId, 'matched_by' => 'hand'])->save();
            $this->audit->record('zkteco.user_matched', $user, old: $old, new: ['device_id' => $device->getKey(), 'user_id' => $user->user_id, 'employee_id' => $employeeId],
                actor: $actor, organizationId: $company->getKey());
        }

        return $ids === [] ? 0 : $this->sync->forward($company, $device, $actor)['recorded'];
    }

    /**
     * Who a device user may be, from the name the device keeps: the one
     * employee whose name holds every word of it (PARVEJ → Parvej Hossain),
     * not matched to another user of the device yet; null when none or several.
     *
     * @param  list<EmployeeRecord>  $employees
     * @param  list<string>  $taken  employee ids matched on this device already
     */
    public static function suggest(?string $deviceName, array $employees, array $taken): ?string
    {
        $words = self::words((string) $deviceName);
        if ($words === []) {
            return null;
        }
        $found = array_values(array_filter($employees, fn ($employee) => ! in_array($employee->id, $taken, true)
            && array_diff($words, self::words($employee->name)) === []));

        return count($found) === 1 ? $found[0]->id : null;
    }

    /** @return list<string> */
    private static function words(string $name): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name)) ?: [], fn (string $word) => mb_strlen($word) > 1));
    }
}
