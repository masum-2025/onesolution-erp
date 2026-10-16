<?php

namespace Modules\FactoryErp\Offline;

use App\Platform\Offline\Contracts\SyncableRecords;
use App\Platform\Offline\SyncOperation;
use App\Platform\Offline\SyncResult;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\FactoryErp\Http\Requests\OutputRequest;
use Modules\FactoryErp\Models\Line;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\Style;
use Modules\FactoryErp\Services\Floor;
use Modules\FactoryErp\Services\Outputs;

/**
 * Sewing output written on a phone without a network (kind
 * factory_erp.output; not money). The phone keeps the entry as written
 * (line, order, day, hour, pieces) and sends it when back online; the
 * same op id counts once. Checked again as if just written: an entry the
 * order can no longer take (more than cut, the order moved on) is refused
 * with its code for the supervisor to look at. The phone keeps the lines
 * in use and the orders in production to write for.
 */
class OutputSync implements SyncableRecords
{
    public function __construct(private Floor $floor, private Outputs $outputs) {}

    public function key(): string
    {
        return 'factory_erp.output';
    }

    public function isMoney(): bool
    {
        return false;
    }

    public function permission(string $action): string
    {
        return 'factory_erp.produce';
    }

    public function rules(): array
    {
        return ['factory_erp.output_by'];
    }

    public function apply(SyncOperation $operation): SyncResult
    {
        if ($operation->action !== SyncOperation::CREATE) {
            return SyncResult::rejected('create_only');
        }
        if (! Gate::forUser($operation->user)->allows('factory_erp.produce', $operation->organization)) {
            return SyncResult::rejected('forbidden');
        }
        $validator = Validator::make($operation->data, array_diff_key(OutputRequest::fields(), ['op_id' => true]));
        if ($validator->fails()) {
            return SyncResult::rejected('invalid', $validator->errors()->toArray());
        }
        try {
            $output = $this->outputs->record($this->floor->companyOf($operation->organization), $operation->organization,
                [...$validator->validated(), 'op_id' => $operation->opId], $operation->user);
        } catch (ValidationException $exception) {
            return SyncResult::rejected('invalid', $exception->errors());
        } catch (TenancyException $exception) {
            return SyncResult::rejected($exception->errorCode());
        }

        return SyncResult::applied($output->getKey(), $output->version);
    }

    /** Lines in use and orders in production at the unit (with their sizes and colours), for the phone to write for. */
    public function changes(Organization $organization, ?CarbonImmutable $since, int $limit): array
    {
        $company = $this->floor->companyOf($organization);
        $units = $this->floor->subtreeIds($organization);
        $lines = $this->floor->query(Line::class, $company)->whereIn('unit_id', $units)->where('is_active', true)->orderBy('code')->limit($limit)->get();
        $orders = $this->floor->query(Order::class, $company)->whereIn('unit_id', $units)->where('status', 'in_production')->orderBy('ship_on')->limit($limit)->get();
        $styles = $this->floor->query(Style::class, $company)->whereKey($orders->pluck('style_id')->unique())->get()->keyBy('id');

        return ['records' => [
            ...$lines->map(fn (Line $line) => ['id' => $line->getKey(), 'type' => 'line', 'code' => $line->code, 'name' => $line->name])->all(),
            ...$orders->map(fn (Order $order) => [
                'id' => $order->getKey(), 'type' => 'order', 'number' => $order->number, 'style_code' => $styles[$order->style_id]?->code,
                'sizes' => $styles[$order->style_id]?->sizes ?? [], 'colors' => $styles[$order->style_id]?->colors ?? [],
            ])->all(),
        ], 'deleted' => []];
    }
}
