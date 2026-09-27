<?php

namespace App\Platform\Payments\Gateways;

use App\Platform\Payments\Contracts\GatewayDriver;
use App\Platform\Payments\Contracts\PaymentGateway;
use App\Platform\Payments\Models\MerchantAccount;

/**
 * SSLCommerz for any store: a store id (shown partly, to recognise the
 * account) and a store password (secret).
 */
class SslCommerzDriver implements GatewayDriver
{
    public function __construct(
        private string $sandboxUrl,
        private string $liveUrl,
        private int $timeout,
        private bool $production,
    ) {}

    public function key(): string
    {
        return 'sslcommerz';
    }

    public function credentialFields(): array
    {
        return [
            'store_id' => ['secret' => false, 'rules' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9_\-]+$/']],
            'store_password' => ['secret' => true, 'rules' => ['required', 'string', 'min:4', 'max:100']],
        ];
    }

    public function currencies(): array
    {
        return ['BDT'];
    }

    public function hint(array $credentials): string
    {
        $id = (string) ($credentials['store_id'] ?? '');

        return mb_strlen($id) <= 6 ? mb_substr($id, 0, 2).'***' : mb_substr($id, 0, 4).'***'.mb_substr($id, -2);
    }

    public function make(array $credentials, string $mode): PaymentGateway
    {
        return $this->gateway($credentials, $mode);
    }

    public function check(array $credentials, string $mode): string
    {
        return $this->gateway($credentials, $mode)->check();
    }

    private function gateway(array $credentials, string $mode): SslCommerzGateway
    {
        $sandbox = $mode !== MerchantAccount::LIVE;

        return new SslCommerzGateway(
            storeId: $credentials['store_id'] ?? null,
            storePassword: $credentials['store_password'] ?? null,
            baseUrl: $sandbox ? $this->sandboxUrl : $this->liveUrl,
            timeout: $this->timeout,
            production: $this->production,
            sandbox: $sandbox,
        );
    }
}
