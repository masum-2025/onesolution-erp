<?php

namespace Modules\OnlineOrders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\OnlineOrders\Services\Couriers;

/**
 * SC-5b: a courier tells us where a parcel stands. No sign-in: the proof is
 * the account's own token (Authorization: Bearer, set in the courier's
 * panel, kept here only as a hash). Unknown company, account or token: 401,
 * one answer for all. Online orders off for the company: 404 (nothing kept).
 */
class CourierWebhookController extends Controller
{
    public function __invoke(Request $request, string $organization, string $courier, Couriers $couriers, ModuleResolver $modules): JsonResponse
    {
        $company = Str::isUlid($organization) ? Organization::query()->find($organization) : null;
        if ($company !== null && (! $modules->isEnabled('inventory', $company) || ! $modules->isEnabled('online_orders', $company))) {
            return response()->json(['status' => 'off'], 404);
        }
        $payload = $request->json()->all();
        if ($company === null || ! $couriers->notice($company, $courier, (string) $request->bearerToken(), is_array($payload) ? $payload : [])) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        return response()->json(['status' => 'success']);
    }
}
