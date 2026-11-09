<?php

namespace Modules\OnlineOrders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\Services\CollectPayment;
use App\Platform\Support\LocaleResolver;
use App\Platform\Support\MoneyText;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\OnlineOrders\Models\Order;
use Modules\OnlineOrders\Payments\OrderCollectable;
use Modules\OnlineOrders\Services\OrderBook;
use Modules\OnlineOrders\Services\Orders;

/**
 * SC-5a: the page a customer opens from the payment link (Messenger): no
 * sign-in, no session, no scripts. The signed link is the proof (it names
 * the company and the order, and ends after online_orders.pay_link_hours);
 * the amount is always the order's own. "Pay" sends them to the company's
 * gateway; coming back shows how it went.
 */
class PayController extends Controller
{
    public function __construct(private Orders $orders, private OrderBook $book) {}

    public function show(Request $request, string $organization, string $order): Response
    {
        [$company, $row] = $this->found($request, $organization, $order);
        $locale = $this->locale($request, $company);
        if ($row === null) {
            return $this->page('expired', ['locale' => $locale], 410);
        }
        $data = $this->facts($company, $row, $locale);

        $paymentId = (string) $request->query('payment', '');
        if (Str::isUlid($paymentId)) {
            $payment = Payment::query()->whereKey($paymentId)->where('organization_id', $company->getKey())
                ->where('subject_type', OrderCollectable::KIND)->where('subject_id', $row->getKey())->first();
            $data['outcome'] = match ($payment?->status) {
                Payment::SUCCEEDED => 'paid',
                Payment::PENDING, Payment::REVIEW => 'checking',
                null => null,
                default => 'failed',
            };
        }

        return $this->page('pay', $data);
    }

    public function start(Request $request, string $organization, string $order, CollectPayment $collect): RedirectResponse|Response
    {
        [$company, $row] = $this->found($request, $organization, $order);
        $locale = $this->locale($request, $company);
        if ($row === null) {
            return $this->page('expired', ['locale' => $locale], 410);
        }
        try {
            $payment = $collect->startForGuest($company, OrderCollectable::KIND, $row->getKey());
        } catch (PaymentException $exception) {
            return $this->page('pay', [...$this->facts($company, $row, $locale), 'error' => $exception->getMessage()], 422);
        }

        return redirect()->away((string) $payment->checkout_url, 303);
    }

    // ---------------------------------------------------------------- inside

    /**
     * The company and the order the link names, when its signature holds.
     *
     * @return array{0: Organization|null, 1: Order|null}
     */
    private function found(Request $request, string $organization, string $order): array
    {
        if (! $request->hasValidRelativeSignature() || ! Str::isUlid($organization) || ! Str::isUlid($order)) {
            return [null, null];
        }
        $company = Organization::query()->find($organization);

        return [$company, $company === null ? null : $this->book->query(Order::class, $company)->whereKey($order)->first()];
    }

    /** @return array<string, mixed> */
    private function facts(Organization $company, Order $order, string $locale): array
    {
        $due = $this->orders->onlineDue($company, $order->getKey());
        $money = fn (int $minor) => MoneyText::shown($minor, $order->currency_code, $locale);

        return [
            'locale' => $locale,
            'company' => $company->displayName($locale),
            'number' => $order->number,
            'total' => $money($order->total_minor),
            'paid' => $order->paid_minor > 0 ? $money($order->paid_minor) : null,
            'amount' => $due === null ? null : $money($due['amount_minor']),
            'rest' => $due === null ? null : $money(max(0, $order->total_minor - $order->paid_minor - $due['amount_minor'])),
            'action' => $this->signedAction(),
            'outcome' => null,
            'error' => null,
        ];
    }

    /** The form posts back to the same signed address (without the coming-back payment id). */
    private function signedAction(): string
    {
        return request()->getRequestUri();
    }

    /** The link's language (the customer's), else the company's own. */
    private function locale(Request $request, ?Organization $company): string
    {
        $lang = $request->query('lang');

        return app(LocaleResolver::class)->resolve(is_string($lang) ? $lang : null, null, $company);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(string $view, array $data, int $status = 200): Response
    {
        $locale = (string) $data['locale'];
        $html = view("online_orders::pay.{$view}", [...$data, 'ui' => fn (string $key, array $replace = []) => __("online_orders::orders.pay.{$key}", $replace, $locale)])->render();
        $styles = preg_match_all('#<style>(.*?)</style>#s', $html, $found)
            ? implode(' ', array_map(fn (string $code) => "'sha256-".base64_encode(hash('sha256', $code, true))."'", array_unique($found[1])))
            : "'none'";

        return response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src {$styles}; script-src 'none'; base-uri 'none'; form-action 'self' https:; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store',
        ]);
    }
}
