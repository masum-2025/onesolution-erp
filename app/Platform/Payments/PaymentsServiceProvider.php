<?php

namespace App\Platform\Payments;

use App\Platform\Payments\Console\ApplyMerchantChanges;
use App\Platform\Payments\Console\ReconcilePayments;
use App\Platform\Payments\Contracts\PaymentFulfiller;
use App\Platform\Payments\Gateways\SslCommerzDriver;
use App\Platform\Payments\Gateways\SslCommerzGateway;
use App\Platform\Payments\Services\PaymentFulfilment;
use App\Platform\Rules\RuleResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Online payments (Phase 5C-2): gateway drivers, payment records, and the
 * gateway callbacks. Which gateway a country offers is a rule. Phase 6: a
 * client's own merchant accounts, and its customers paying it (collections).
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GatewayRegistry::class, function ($app) {
            $registry = new GatewayRegistry($app->make(RuleResolver::class));

            $registry->register(new SslCommerzGateway(
                storeId: config('payments.sslcommerz.store_id'),
                storePassword: config('payments.sslcommerz.store_password'),
                baseUrl: (string) config('payments.sslcommerz.base_url'),
                timeout: (int) config('payments.sslcommerz.timeout'),
                production: $app->isProduction(),
            ));

            // Clients' own merchant accounts (Phase 6).
            $registry->registerDriver(new SslCommerzDriver(
                sandboxUrl: (string) config('payments.sslcommerz.base_url'),
                liveUrl: (string) config('payments.sslcommerz.live_base_url'),
                timeout: (int) config('payments.sslcommerz.timeout'),
                production: $app->isProduction(),
            ));

            return $registry;
        });

        // What a confirmed payment gives: self-serve plans and invoices, or a client's collection.
        $this->app->bind(PaymentFulfiller::class, PaymentFulfilment::class);

        // What customers can pay clients for, from module manifests (Phase 6).
        $this->app->singleton(PaymentCollectables::class);
    }

    public function boot(): void
    {
        // Starting a payment: a few per minute per person is plenty.
        RateLimiter::for('payments-start', fn (Request $request) => Limit::perMinute(10)->by('payments-start:'.($request->user()?->getKey() ?? $request->ip())));

        // A client's gateway accounts: each call asks the gateway or checks a password (Phase 6).
        RateLimiter::for('merchant-accounts', fn (Request $request) => Limit::perMinute(6)->by('merchant-accounts:'.($request->user()?->getKey() ?? $request->ip())));

        // Gateway callbacks come from a few addresses; this only stops floods.
        RateLimiter::for('payments-callback', fn (Request $request) => Limit::perMinute(120)->by('payments-callback:'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcilePayments::class, ApplyMerchantChanges::class]);
        }
    }
}
