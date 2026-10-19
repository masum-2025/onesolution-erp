<?php

namespace Modules\Leave\Offline;

use App\Platform\Offline\Contracts\SyncableRecords;
use App\Platform\Offline\SyncOperation;
use App\Platform\Offline\SyncResult;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Leave\Exceptions\LeaveException;
use Modules\Leave\Services\Place;
use Modules\Leave\Services\Requests;

/**
 * Asking for leave while the phone is offline (kind leave.request): the
 * request is sent when the phone is back online, with the same checks as
 * online (balance, overlap, how far back). Only creates, only for oneself;
 * the operation's op_id makes a repeated send the same request.
 */
class LeaveRequestSync implements SyncableRecords
{
    public function __construct(private Requests $requests, private Place $place, private EmployeeDirectory $directory) {}

    public function key(): string
    {
        return 'leave.request';
    }

    public function isMoney(): bool
    {
        return false;
    }

    public function permission(string $action): string
    {
        return 'leave.request';
    }

    public function rules(): array
    {
        return ['leave.backdate_days', 'leave.beyond_balance', 'leave.year_start_month'];
    }

    public function apply(SyncOperation $operation): SyncResult
    {
        if ($operation->action !== SyncOperation::CREATE) {
            return SyncResult::rejected('create_only');
        }
        if (! Gate::forUser($operation->user)->allows('leave.request', $operation->organization)) {
            return SyncResult::rejected('forbidden');
        }
        $validator = Validator::make($operation->data, [
            'type_id' => ['required', 'string', 'max:26'],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'from_half' => ['sometimes', 'boolean'],
            'to_half' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        if ($validator->fails()) {
            return SyncResult::rejected('invalid', $validator->errors()->toArray());
        }

        try {
            $company = $this->place->companyOf($operation->organization);
            $employee = $this->directory->forUser($company, $operation->user) ?? throw LeaveException::notLinked();
            $request = $this->requests->ask($company, $employee, [...$validator->validated(), 'op_id' => $operation->opId], $operation->user);
        } catch (LeaveException $exception) {
            return SyncResult::rejected($exception->errorCode());
        } catch (ValidationException $exception) {
            return SyncResult::rejected('invalid', $exception->errors());
        }

        return SyncResult::applied($request->getKey(), $request->version);
    }

    /** Requests are decided online: nothing to catch up on. */
    public function changes(Organization $organization, ?CarbonImmutable $since, int $limit): array
    {
        return ['records' => [], 'deleted' => []];
    }
}
