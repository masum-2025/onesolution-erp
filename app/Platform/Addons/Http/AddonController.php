<?php

namespace App\Platform\Addons\Http;

use App\Http\Controllers\Controller;
use App\Platform\Addons\Addons;
use App\Platform\Addons\AddonStore;
use App\Platform\Addons\Exceptions\AddonException;
use App\Platform\Billing\Exceptions\BillingException;
use App\Platform\Billing\Http\Requests\EmptyRequest;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The add-on store of an account (its top organization): see what can be
 * added, try or buy a module, cancel or keep it. Seeing needs billing.view
 * or modules.manage there; every change needs addons.buy.
 */
class AddonController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private Addons $addons, private AddonStore $store) {}

    public function index(string $organization): JsonResponse
    {
        $root = $this->root($organization);

        return response()->json(['data' => $this->store->forAccount($root), 'can_buy' => Gate::allows('addons.buy', $root)]);
    }

    public function trial(AddonOpRequest $request, string $organization, string $module): JsonResponse
    {
        $root = $this->buyer($organization);
        $addon = $this->addons->trial($root, $module, $request->user(), $request->validated('op_id'));

        return $this->done($root, $addon->wasRecentlyCreated ? 201 : 200, __('addons.messages.trial_started'));
    }

    public function buy(AddonOpRequest $request, string $organization, string $module): JsonResponse
    {
        $root = $this->buyer($organization);
        $this->addons->buy($root, $module, $request->user(), $request->validated('op_id'));

        return $this->done($root, 200, __($this->addons->billsClient($root) ? 'addons.messages.bought' : 'addons.messages.bought_provider'));
    }

    public function cancel(EmptyRequest $request, string $organization, string $module): JsonResponse
    {
        $root = $this->buyer($organization);
        $this->addons->cancel($root, $module, $request->user());

        return $this->done($root, 200, __('addons.messages.cancelled'));
    }

    public function resume(EmptyRequest $request, string $organization, string $module): JsonResponse
    {
        $root = $this->buyer($organization);
        $this->addons->resume($root, $module, $request->user());

        return $this->done($root, 200, __('addons.messages.resumed'));
    }

    private function done(Organization $root, int $status, string $message): JsonResponse
    {
        return response()->json(['data' => $this->store->forAccount($root->refresh()), 'message' => $message], $status);
    }

    /** The account's top organization, which the caller must see and may look at the store of. */
    private function root(string $organization): Organization
    {
        $organization = $this->findVisible($organization);
        $root = $organization->isRoot() ? $organization : Organization::query()->findOrFail($organization->root_id);

        if (! Gate::any(['billing.view', 'modules.manage', 'addons.buy'], $root)) {
            throw BillingException::topLevelOnly($root->displayName());
        }

        return $root->loadMissing('partner');
    }

    /** Someone who may buy here, in an open store that lets clients buy themselves. */
    private function buyer(string $organization): Organization
    {
        $root = $this->root($organization);

        if (! Gate::allows('addons.buy', $root)) {
            throw AddonException::notAllowed();
        }
        if (! $this->addons->rule('billing.addons_enabled', $root)) {
            throw AddonException::storeClosed();
        }
        if (! $this->addons->rule('billing.addon_self_purchase', $root)) {
            throw AddonException::selfPurchaseOff($root->partner->name);
        }

        return $root;
    }
}
