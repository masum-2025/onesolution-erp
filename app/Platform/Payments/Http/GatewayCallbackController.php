<?php

namespace App\Platform\Payments\Http;

use App\Http\Controllers\Controller;
use App\Platform\Payments\Contracts\PaymentGateway;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\GatewayEvent;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\PaymentCollectables;
use App\Platform\Payments\Services\PaymentConfirmer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where gateways reach us. Both routes run without a session or CSRF (the
 * gateway posts from its own site; a session here would replace the
 * person's), and trust nothing they receive: every message is checked with
 * the gateway before it changes anything.
 *
 * Whose credentials check a message follows the payment it names: a
 * client's merchant account for its customers' payments (Phase 6), else the
 * platform's own account. A message checked with one account's credentials
 * only ever applies to that account's payments (PaymentConfirmer).
 *
 * - notify: the gateway's server-to-server notice.
 * - return: the person's browser coming back from the payment page; it is
 *   checked the same way, then sent on to the payment's status screen.
 */
class GatewayCallbackController extends Controller
{
    public function __construct(
        private GatewayRegistry $gateways,
        private PaymentConfirmer $confirmer,
        private PaymentCollectables $collectables,
    ) {}

    public function notify(Request $request, string $gateway): Response
    {
        [$driver, $payment] = $this->driver($request, $gateway);
        $result = $driver->resolve($request);

        if ($result === null || ($payment !== null && $result->paymentId !== $payment->getKey())) {
            return response('Not accepted', 400);
        }

        try {
            $this->confirmer->apply($driver, GatewayEvent::NOTIFICATION, $result, $payment?->merchant_account_id);
        } catch (Throwable $exception) {
            // Not applied: an error status makes the gateway send its notice again.
            Log::error('A payment notice could not be applied.', ['gateway' => $gateway, 'payment' => $result->paymentId, 'error' => $exception::class]);

            return response('Try again', 500);
        }

        return response('OK', 200);
    }

    public function return(Request $request, string $gateway, string $outcome): RedirectResponse
    {
        [$driver, $payment] = $this->driver($request, $gateway);
        $paymentId = (string) $request->input('tran_id', '');

        if ($request->isMethod('post')) {
            try {
                $result = $driver->resolve($request);
                if ($result !== null && ($payment === null || $result->paymentId === $payment->getKey())) {
                    $this->confirmer->apply($driver, GatewayEvent::RETURN, $result, $payment?->merchant_account_id);
                }
            } catch (Throwable $exception) {
                // The status screen asks the gateway again; the notice will also arrive.
                Log::warning('A payment return could not be applied yet.', ['gateway' => $gateway, 'payment' => $paymentId, 'error' => $exception::class]);
            }
        }

        return redirect($this->back($payment, $paymentId), 303);
    }

    /**
     * The gateway for the payment the message names: its merchant account's,
     * or the platform's own for anything else.
     *
     * @return array{0: PaymentGateway, 1: Payment|null}
     */
    private function driver(Request $request, string $key): array
    {
        $platform = $this->gateways->get($key);
        abort_if($platform === null, 404);

        $reference = $platform->paymentReference($request);
        $payment = $reference !== null && Str::isUlid($reference)
            ? Payment::query()->whereKey($reference)->where('gateway', $key)->first()
            : null;

        if ($payment?->merchant_account_id !== null) {
            $driver = $this->gateways->forPayment($payment);
            abort_if($driver === null || ! $driver->isAvailable(), 404);

            return [$driver, $payment];
        }

        abort_if(! $platform->isAvailable(), 404);

        return [$platform, $payment];
    }

    /** The payer's own screen: the module's for a collection, else the billing status page. */
    private function back(?Payment $payment, string $paymentId): string
    {
        if ($payment?->purpose === Payment::COLLECTION && $this->collectables->has((string) $payment->subject_type)) {
            $path = $this->collectables->provider((string) $payment->subject_type)->returnPath($payment);

            // A path in this app only: never "//host" or "/\host" (another site).
            return preg_match('#^/(?![/\\\\])#', $path) === 1 ? $path : '/';
        }

        return Str::isUlid($paymentId) ? "/billing/payments/{$paymentId}" : '/billing';
    }
}
