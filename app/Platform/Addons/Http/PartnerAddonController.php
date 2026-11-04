<?php

namespace App\Platform\Addons\Http;

use App\Http\Controllers\Controller;
use App\Platform\Addons\AddonPriceBook;
use App\Platform\Addons\Addons;
use App\Platform\Addons\AddonStore;
use App\Platform\Addons\Exceptions\AddonException;
use App\Platform\Billing\Http\Requests\EmptyRequest;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Partners\Services\PartnerModuleService;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Partner console: the add-ons its clients can buy, the platform's price,
 * the partner's own price for its clients (and, for a wholesale partner,
 * what it pays us); a client's add-ons, started or cancelled for it.
 * Prices: owner or billing staff. The partner comes from the context only.
 */
class PartnerAddonController extends Controller
{
    use PartnerConsole;

    public function __construct(
        private ModuleRegistry $registry,
        private AddonPriceBook $prices,
        private Addons $addons,
        private AddonStore $store,
        private PartnerModuleService $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function index(): JsonResponse
    {
        $partner = $this->partner();
        $offered = $this->modules->offered($partner);
        $wholesale = $partner->billing_mode === BillingMode::Wholesale;
        $billingCurrency = (string) $this->rules->get('billing.partner_currency', $this->contexts->forPartner($partner));

        // Every module for sale, each in every currency add-ons are priced in (a price may be set where none is yet).
        $house = (bool) $partner->is_house;
        $currencies = $this->prices->knownCurrencies($partner, $billingCurrency);
        $data = [];
        foreach ($this->registry->all() as $key => $module) {
            if (! $module->sellable || ($offered !== null && ! in_array($key, $offered, true))) {
                continue;
            }

            $data[] = [
                'key' => $key,
                'name' => $module->label(),
                'description' => __($module->description),
                'category' => $module->category,
                'prices' => array_map(fn (string $currency) => [
                    'currency' => $currency,
                    'platform_minor' => $this->prices->platformListPrice($key, $currency),
                    'own_minor' => $house ? null : $this->prices->partnerListPrice($partner, $key, $currency),
                    // The house sets the platform's prices: what clients pay and what wholesale partners pay us.
                    ...($house ? ['wholesale_minor' => $this->prices->platformWholesalePrice($key, $currency)] : []),
                ], $currencies),
                'wholesale' => $wholesale ? [
                    'currency' => $billingCurrency,
                    'amount_minor' => $this->prices->wholesalePrice($partner, $key, $billingCurrency),
                ] : null,
            ];
        }

        $edits = $this->hasRole(PartnerUserRole::Owner, PartnerUserRole::Billing);

        return response()->json(['data' => $data, 'currencies' => $currencies, 'can_edit' => $edits && ! $house, 'can_set_platform' => $edits && $house]);
    }

    /**
     * The platform's price of a module (the house's owner or billing staff
     * only): the list price clients pay, or the wholesale price partners pay
     * us, in a currency, from today; empty = not sold in that currency.
     */
    public function platform(SetAddonPriceRequest $request, string $module): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Billing);
        if (! $this->partner()->is_house) {
            throw AddonException::notHouse();
        }
        if (! $this->registry->has($module) || ! $this->registry->get($module)->sellable) {
            throw AddonException::unknown();
        }

        $this->prices->set(null, $request->validated('kind'), $module, $request->validated('currency'), $request->validated('amount_minor'),
            CarbonImmutable::today(), $request->user(), $request->validated('reason'));

        return response()->json(['message' => __('addons.messages.platform_price_saved')]);
    }

    public function update(SetAddonPriceRequest $request, string $module): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Billing);
        if (! $this->registry->has($module) || ! $this->registry->get($module)->sellable) {
            throw AddonException::unknown();
        }

        $this->prices->set(
            $this->partner(),
            'list',
            $module,
            $request->validated('currency'),
            $request->validated('amount_minor'),
            CarbonImmutable::today(),
            $request->user(),
            $request->validated('reason'),
        );

        return response()->json(['message' => __('addons.messages.price_saved')]);
    }

    public function forClient(string $client): JsonResponse
    {
        return response()->json(['data' => $this->store->forAccount($this->client($client)->loadMissing('partner'))]);
    }

    public function buyFor(AddonOpRequest $request, string $client, string $module): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Billing);
        $root = $this->client($client)->loadMissing('partner');
        $this->addons->buy($root, $module, $request->user(), $request->validated('op_id'));

        return response()->json(['data' => $this->store->forAccount($root->refresh()), 'message' => __('addons.messages.bought_for_client')]);
    }

    public function cancelFor(EmptyRequest $request, string $client, string $module): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Billing);
        $root = $this->client($client)->loadMissing('partner');
        $this->addons->cancel($root, $module, $request->user());

        return response()->json(['data' => $this->store->forAccount($root->refresh()), 'message' => __('addons.messages.cancelled')]);
    }
}
