<?php

namespace Modules\Pos\Offline;

use App\Platform\Offline\Contracts\SyncableRecords;
use App\Platform\Offline\SyncOperation;
use App\Platform\Offline\SyncResult;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Pos\Models\Register;
use Modules\Pos\Services\Counters;
use Modules\Pos\Services\Sales;
use Modules\Pos\Services\Tills;

/**
 * A sale made while the counter was offline (kind pos.sale, money:
 * append-only, only with offline_mode.allow_offline_payments). The device
 * keeps the sale as made (time, shift, items, payments) and sends it when
 * back online; the same op id is the same sale. What no longer holds
 * marks the sale for review rather than losing it (see Sales). Only where
 * rule pos.offline_sales allows. The catalogue is what a device keeps.
 */
class SaleSync implements SyncableRecords
{
    public function __construct(private Tills $tills, private Sales $sales, private Counters $counters, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function key(): string
    {
        return 'pos.sale';
    }

    public function isMoney(): bool
    {
        return true;
    }

    public function permission(string $action): string
    {
        return 'pos.sell';
    }

    public function rules(): array
    {
        return ['pos.offline_sales', 'pos.prices_include_tax', 'pos.max_discount_percent'];
    }

    public function apply(SyncOperation $operation): SyncResult
    {
        if ($operation->action !== SyncOperation::CREATE) {
            return SyncResult::rejected('create_only');
        }
        if ($operation->madeAt === null) {
            return SyncResult::rejected('time_missing');
        }
        $validator = Validator::make($operation->data, [
            'register_id' => ['required', 'string', 'max:26'],
            'session_id' => ['nullable', 'string', 'max:26'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'string', 'max:26'],
            'lines.*.quantity_milli' => ['required', 'integer', 'min:1'],
            'lines.*.discount_minor' => ['nullable', 'integer', 'min:0'],
            'payments' => ['required', 'array', 'min:1', 'max:5'],
            'payments.*.method' => ['required', 'in:'.implode(',', Register::METHODS)],
            'payments.*.amount_minor' => ['required', 'integer', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'],
        ]);
        if ($validator->fails()) {
            return SyncResult::rejected('invalid', $validator->errors()->toArray());
        }
        $data = $validator->validated();
        $company = $this->tills->companyOf($operation->organization);
        $register = $this->tills->query(Register::class, $company)->whereKey($data['register_id'])->first();
        if ($register === null || ! in_array($register->unit_id, $this->tills->subtreeIds($operation->organization), true)) {
            return SyncResult::rejected('register_not_found');
        }
        $unit = Organization::query()->findOrFail($register->unit_id);
        if (! Gate::forUser($operation->user)->allows('pos.sell', $unit)) {
            return SyncResult::rejected('forbidden');
        }
        if (! $this->rules->get('pos.offline_sales', $this->contexts->forOrganization($unit))) {
            return SyncResult::quarantined('offline_sales_off');
        }

        try {
            $sale = $this->sales->sell($company, $register, [...$data, 'op_id' => $operation->opId], $operation->user, $operation->madeAt);
        } catch (ValidationException $exception) {
            return SyncResult::rejected('invalid', $exception->errors());
        } catch (TenancyException $exception) {
            // Paid too little or a method not taken: kept aside for a person to look at.
            return SyncResult::quarantined($exception->errorCode());
        }

        return SyncResult::applied($sale->getKey(), $sale->version);
    }

    /** A device catches up on the catalogue of the counters it may sell at. */
    public function changes(Organization $organization, ?CarbonImmutable $since, int $limit): array
    {
        $company = $this->tills->companyOf($organization);
        $register = $this->tills->query(Register::class, $company)->whereIn('unit_id', $this->tills->subtreeIds($organization))->where('is_active', true)->first();
        if ($register === null) {
            return ['records' => [], 'deleted' => []];
        }

        return ['records' => array_slice($this->counters->catalogue($company, $register)['items'], 0, $limit), 'deleted' => []];
    }
}
