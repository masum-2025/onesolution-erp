<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Identity\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Services\Customers;
use Modules\Pos\Http\Controllers\Concerns\FindsPos;
use Modules\Pos\Models\Sale;
use Modules\Pos\Services\Tills;

/**
 * A returning customer by mobile number at the counter (pos.sell or
 * pos.view): with CRM on, its contact (name, points, purchases); without
 * it, the name and count of the earlier sales with that number at this
 * company. Rate limited with the sales.
 */
class CustomerController extends Controller
{
    use FindsPos;

    public function __construct(private Tills $tills) {}

    public function __invoke(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        abort_unless(Gate::any(['pos.sell', 'pos.view'], $unit), 403);
        $typed = (string) $request->validate(['phone' => ['required', 'string', 'max:20']])['phone'];
        $phone = PhoneNumber::normalize($typed, $this->tills->country($company));
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('pos::pos.validation.phone')]);
        }

        if (class_exists(Customers::class) && ($contact = app(Customers::class)->lookup($company, $phone)) !== null) {
            return response()->json(['data' => [...$contact, 'found' => true, 'source' => 'crm']]);
        }
        $sales = $this->tills->query(Sale::class, $company)->where('customer_phone', $phone)->where('kind', 'sale');
        $last = (clone $sales)->orderByDesc('sold_at')->first(['customer_name', 'sold_at']);

        return response()->json(['data' => [
            'found' => $last !== null, 'source' => 'sales', 'id' => null, 'phone' => $phone, 'name' => $last?->customer_name,
            'purchases' => (clone $sales)->count(), 'last_purchase_on' => $last?->sold_at->toDateString(), 'points' => null,
        ]]);
    }
}
