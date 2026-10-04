<?php

namespace Modules\Attendance\Offline;

use App\Platform\Offline\Contracts\SyncableRecords;
use App\Platform\Offline\SyncOperation;
use App\Platform\Offline\SyncResult;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Modules\Attendance\Exceptions\AttendanceException;
use Modules\Attendance\Services\Punches;
use Modules\Attendance\Services\Workplace;

/**
 * Checking in while the phone is offline (kind attendance.punch): the
 * phone keeps the tap with the time it happened (and, where the unit asks
 * for it, where), and sends it when it is back online. Only creates; the
 * time must be recent (attendance.offline_max_age_hours) and the same
 * checks as online apply. A repeated op_id is the same punch.
 */
class PunchSync implements SyncableRecords
{
    public function __construct(private Punches $punches, private Workplace $workplace) {}

    public function key(): string
    {
        return 'attendance.punch';
    }

    public function isMoney(): bool
    {
        return false;
    }

    public function permission(string $action): string
    {
        return 'attendance.punch';
    }

    public function rules(): array
    {
        return ['attendance.self_punch', 'attendance.geo_fence_required', 'attendance.geo_max_accuracy_m', 'attendance.offline_max_age_hours'];
    }

    public function apply(SyncOperation $operation): SyncResult
    {
        if ($operation->action !== SyncOperation::CREATE) {
            return SyncResult::rejected('create_only');
        }
        if ($operation->madeAt === null) {
            return SyncResult::rejected('time_missing');
        }
        if (! Gate::forUser($operation->user)->allows('attendance.punch', $operation->organization)) {
            return SyncResult::rejected('forbidden');
        }
        $validator = Validator::make($operation->data, [
            'latitude_micro' => ['nullable', 'integer', 'required_with:longitude_micro,accuracy_m'],
            'longitude_micro' => ['nullable', 'integer', 'required_with:latitude_micro'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'required_with:latitude_micro'],
        ]);
        if ($validator->fails()) {
            return SyncResult::rejected('invalid', $validator->errors()->toArray());
        }
        $data = $validator->validated();
        $fix = isset($data['latitude_micro']) ? ['latitude_micro' => (int) $data['latitude_micro'], 'longitude_micro' => (int) $data['longitude_micro'], 'accuracy_m' => (int) $data['accuracy_m']] : null;

        try {
            $punch = $this->punches->offline($this->workplace->companyOf($operation->organization), $operation->user, $operation->madeAt, $operation->opId, $fix);
        } catch (AttendanceException $exception) {
            return SyncResult::rejected($exception->errorCode());
        }

        return SyncResult::applied($punch->getKey(), 1);
    }

    /** Punches are not changed on a phone: nothing to catch up on. */
    public function changes(Organization $organization, ?CarbonImmutable $since, int $limit): array
    {
        return ['records' => [], 'deleted' => []];
    }
}
