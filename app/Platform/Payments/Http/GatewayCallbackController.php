<?php

namespace App\Platform\Payments\Http;

use App\Http\Controllers\Controller;
use App\Platform\Payments\Contracts\PaymentGateway;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\GatewayEvent;
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
 * - notify: the gateway's server-to-server notice.
 * - return: the person's browser coming back from the payment page; it is
 *   checked the same way, then sent on to the payment's status screen.
 */
class GatewayCallbackController extends Controller
{
    public function __construct(private GatewayRegistry $gateways, private PaymentConfirmer $confirmer) {}

    public function notify(Request $request, string $gateway): Response
    {
        $driver = $this->driver($gateway);
        $result = $driver->resolve($request);

        if ($result === null) {
            return response('Not accepted', 400);
        }

        try {
            $this->confirmer->apply($driver, GatewayEvent::NOTIFICATION, $result);
        } catch (Throwable $exception) {
            // Not applied: an error status makes the gateway send its notice again.
            Log::error('A payment notice could not be applied.', ['gateway' => $gateway, 'payment' => $result->paymentId, 'error' => $exception::class]);

            return response('Try again', 500);
        }

        return response('OK', 200);
    }

    public function return(Request $request, string $gateway, string $outcome): RedirectResponse
    {
        $driver = $this->driver($gateway);
        $paymentId = (string) $request->input('tran_id', '');

        if ($request->isMethod('post')) {
            try {
                $result = $driver->resolve($request);
                if ($result !== null) {
                    $this->confirmer->apply($driver, GatewayEvent::RETURN, $result);
                }
            } catch (Throwable $exception) {
                // The status screen asks the gateway again; the notice will also arrive.
                Log::warning('A payment return could not be applied yet.', ['gateway' => $gateway, 'payment' => $paymentId, 'error' => $exception::class]);
            }
        }

        return redirect(Str::isUlid($paymentId) ? "/billing/payments/{$paymentId}" : '/billing', 303);
    }

    private function driver(string $key): PaymentGateway
    {
        $driver = $this->gateways->get($key);
        abort_if($driver === null || ! $driver->isAvailable(), 404);

        return $driver;
    }
}
