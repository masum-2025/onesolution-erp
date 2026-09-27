<?php

namespace App\Platform\Payments\Gateways;

use App\Platform\Payments\CallbackUrls;
use App\Platform\Payments\Contracts\PaymentGateway;
use App\Platform\Payments\DecimalAmount;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Payments\GatewayResult;
use App\Platform\Payments\GatewaySession;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\PaymentCustomer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SSLCommerz (Bangladesh: cards, bKash, Nagad and banks on one hosted page),
 * SANDBOX ONLY in this build.
 *
 * A payment counts only after SSLCommerz's validation API confirms it by
 * val_id; the notification's verify_sign is checked too, and is the only
 * thing trusted for failures and cancellations (they unlock nothing).
 */
class SslCommerzGateway implements PaymentGateway
{
    /** What we keep from SSLCommerz messages: no card numbers, no signatures. */
    private const KEPT_FIELDS = [
        'status', 'tran_id', 'val_id', 'amount', 'currency', 'currency_type', 'currency_amount',
        'store_amount', 'card_type', 'bank_tran_id', 'tran_date', 'risk_level', 'risk_title', 'error',
    ];

    public function __construct(
        private ?string $storeId,
        private ?string $storePassword,
        private string $baseUrl,
        private int $timeout,
        private bool $production,
    ) {}

    public function key(): string
    {
        return 'sslcommerz';
    }

    /**
     * Needs credentials, and never runs in production: the sandbox takes no
     * real money, so it must never unlock a plan there.
     */
    public function isAvailable(): bool
    {
        return ! $this->production && filled($this->storeId) && filled($this->storePassword);
    }

    public function supportsCurrency(string $currency): bool
    {
        return $currency === 'BDT';
    }

    public function isTestMode(): bool
    {
        return true;
    }

    public function start(Payment $payment, PaymentCustomer $customer, CallbackUrls $urls): GatewaySession
    {
        try {
            $response = $this->http()->asForm()->post('/gwprocess/v4/api.php', [
                'store_id' => $this->storeId,
                'store_passwd' => $this->storePassword,
                'total_amount' => DecimalAmount::fromMinor($payment->amount_minor, $payment->currency_code),
                'currency' => $payment->currency_code,
                'tran_id' => $payment->getKey(),
                'success_url' => $urls->success,
                'fail_url' => $urls->fail,
                'cancel_url' => $urls->cancel,
                'ipn_url' => $urls->notify,
                'cus_name' => $customer->name,
                // The form requires these; a digital service has no address to ship to.
                'cus_email' => $customer->email ?? 'not-provided@'.parse_url($urls->notify, PHP_URL_HOST),
                'cus_phone' => $customer->phone ?? 'N/A',
                'cus_add1' => 'N/A',
                'cus_city' => 'N/A',
                'cus_country' => $customer->countryCode,
                'shipping_method' => 'NO',
                'num_of_item' => 1,
                'product_name' => $payment->plan_key ?? 'invoice',
                'product_category' => 'subscription',
                'product_profile' => 'non-physical-goods',
            ]);
        } catch (ConnectionException) {
            Log::warning('SSLCommerz could not be reached to start a payment.', ['payment' => $payment->getKey()]);

            throw PaymentException::gatewayUnavailable();
        }

        $url = $response->json('GatewayPageURL');
        if (! $response->successful() || $response->json('status') !== 'SUCCESS' || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            Log::warning('SSLCommerz refused to start a payment.', [
                'payment' => $payment->getKey(),
                'http' => $response->status(),
                'reason' => mb_substr((string) $response->json('failedreason'), 0, 200),
            ]);

            throw PaymentException::gatewayUnavailable();
        }

        $session = $response->json('sessionkey');

        return new GatewaySession($url, is_string($session) ? mb_substr($session, 0, 100) : null);
    }

    public function resolve(Request $request): ?GatewayResult
    {
        $payload = $request->post();
        $tranId = $payload['tran_id'] ?? null;
        if (! is_string($tranId) || $tranId === '') {
            return null;
        }

        // Success: only SSLCommerz's own validation answer counts.
        $valId = $payload['val_id'] ?? null;
        if (in_array($payload['status'] ?? null, ['VALID', 'VALIDATED'], true) && is_string($valId) && $valId !== '') {
            $validated = $this->validate($valId);

            return $validated !== null && $validated->paymentId === $tranId ? $validated : null;
        }

        // Anything else must carry a valid signature.
        if (! $this->signatureValid($payload)) {
            return null;
        }

        $status = $this->status((string) ($payload['status'] ?? ''));

        return new GatewayResult(
            paymentId: $tranId,
            status: $status === GatewayResult::SUCCEEDED ? GatewayResult::PENDING : $status,
            eventRef: 'status:'.$tranId.':'.($payload['status'] ?? ''),
            gatewayStatus: mb_substr((string) ($payload['status'] ?? ''), 0, 30),
            payload: $this->keep($payload),
        );
    }

    public function lookup(Payment $payment): ?GatewayResult
    {
        try {
            $response = $this->http()->get('/validator/api/merchantTransIDvalidationAPI.php', [
                'tran_id' => $payment->getKey(),
                'store_id' => $this->storeId,
                'store_passwd' => $this->storePassword,
                'format' => 'json',
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $elements = $response->successful() ? $response->json('element') : null;
        if (! is_array($elements) || $elements === []) {
            return null;
        }

        // The best news first: one successful attempt settles the payment.
        foreach ($elements as $element) {
            if (is_array($element) && in_array($element['status'] ?? null, ['VALID', 'VALIDATED'], true) && ($element['tran_id'] ?? null) === $payment->getKey()) {
                return $this->fromValidation($element);
            }
        }

        $last = end($elements);
        if (! is_array($last) || ($last['tran_id'] ?? null) !== $payment->getKey()) {
            return null;
        }

        return new GatewayResult(
            paymentId: $payment->getKey(),
            status: $this->status((string) ($last['status'] ?? '')),
            eventRef: 'lookup:'.$payment->getKey().':'.($last['status'] ?? ''),
            gatewayStatus: mb_substr((string) ($last['status'] ?? ''), 0, 30),
            payload: $this->keep($last),
        );
    }

    private function validate(string $valId): ?GatewayResult
    {
        try {
            $response = $this->http()->get('/validator/api/validationserverAPI.php', [
                'val_id' => $valId,
                'store_id' => $this->storeId,
                'store_passwd' => $this->storePassword,
                'v' => 1,
                'format' => 'json',
            ]);
        } catch (ConnectionException) {
            // Not known yet; the notification is sent again and the status page asks later.
            return null;
        }

        $data = $response->successful() ? $response->json() : null;
        if (! is_array($data) || ! in_array($data['status'] ?? null, ['VALID', 'VALIDATED'], true) || ! is_string($data['tran_id'] ?? null)) {
            return null;
        }

        return $this->fromValidation($data);
    }

    /**
     * @param  array<string, mixed>  $data  A validated transaction.
     */
    private function fromValidation(array $data): GatewayResult
    {
        // currency_type/currency_amount are what was charged; amount is the store's settlement.
        $currency = (string) ($data['currency_type'] ?? $data['currency'] ?? '');
        $amount = (string) ($data['currency_amount'] ?? $data['amount'] ?? '');
        $valid = preg_match('/^[A-Z]{3}$/', $currency) === 1;

        return new GatewayResult(
            paymentId: (string) $data['tran_id'],
            status: GatewayResult::SUCCEEDED,
            eventRef: 'valid:'.($data['val_id'] ?? $data['tran_id']),
            gatewayStatus: mb_substr((string) ($data['status'] ?? ''), 0, 30),
            amountMinor: $valid ? DecimalAmount::toMinor($amount, $currency) : null,
            currency: $valid ? $currency : null,
            gatewayTxn: isset($data['val_id']) ? mb_substr((string) $data['val_id'], 0, 100) : null,
            method: isset($data['card_type']) ? mb_substr((string) $data['card_type'], 0, 60) : null,
            risky: (string) ($data['risk_level'] ?? '0') === '1',
            payload: $this->keep($data),
        );
    }

    private function status(string $status): string
    {
        return match ($status) {
            'VALID', 'VALIDATED' => GatewayResult::SUCCEEDED,
            'FAILED', 'EXPIRED' => GatewayResult::FAILED,
            'CANCELLED' => GatewayResult::CANCELLED,
            default => GatewayResult::PENDING,
        };
    }

    /**
     * SSLCommerz signs a message as md5 over the fields it lists in
     * verify_key plus md5(store password), sorted by name.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signatureValid(array $payload): bool
    {
        $sign = $payload['verify_sign'] ?? null;
        $keys = $payload['verify_key'] ?? null;
        if (! is_string($sign) || ! is_string($keys) || $keys === '' || ! filled($this->storePassword)) {
            return false;
        }

        $data = [];
        foreach (explode(',', $keys) as $key) {
            $data[$key] = is_scalar($payload[$key] ?? null) ? (string) $payload[$key] : '';
        }
        $data['store_passwd'] = md5((string) $this->storePassword);
        ksort($data);

        $text = implode('&', array_map(fn ($key, $value) => $key.'='.$value, array_keys($data), $data));

        return hash_equals(md5($text), $sign);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function keep(array $data): array
    {
        $kept = [];
        foreach (self::KEPT_FIELDS as $field) {
            if (isset($data[$field]) && is_scalar($data[$field])) {
                $kept[$field] = mb_substr((string) $data[$field], 0, 200);
            }
        }

        return $kept;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->timeout($this->timeout)->acceptJson();
    }
}
