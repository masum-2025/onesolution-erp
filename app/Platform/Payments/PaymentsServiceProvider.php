<?php

namespace App\Platform\Payments;

use App\Platform\Billing\SelfServe\Fulfilment;
use App\Platform\Payments\Console\ReconcilePayments;
use App\Platform\Payments\Contracts\PaymentFulfiller;
use App\Platform\Payments\Gateways\SslCommerzGateway;
use App\Platform\Rules\RuleResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Online payments (Phase 5C-2): gateway drivers, payment records, and the
 * gateway callbacks. Which gateway a country offers is a rule.
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

            return $registry;
        });

        // What a confirmed payment buys: self-serve plans and invoices.
        $this->app->bind(PaymentFulfiller::class, Fulfilment::class);
    }

    public function boot(): void
    {
        // Starting a payment: a few per minute per person is plenty.
        RateLimiter::for('payments-start', fn (Request $request) => Limit::perMinute(10)->by('payments-start:'.($request->user()?->getKey() ?? $request->ip())));

        // Gateway callbacks come from a few addresses; this only stops floods.
        RateLimiter::for('payments-callback', fn (Request $request) => Limit::perMinute(120)->by('payments-callback:'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcilePayments::class]);
        }
    }
}
