<?php

namespace Modules\OnlineOrders\Couriers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Steadfast Courier (Bangladesh), its merchant API v1: the company's own
 * Api-Key and Secret-Key, one parcel per order (the order number is the
 * invoice, so the courier refuses booking the same order twice), cash to
 * collect in taka. Its webhook sends a Bearer token the company set in
 * Steadfast's panel (checked by Couriers, not here).
 */
class SteadfastDriver implements CourierDriver
{
    public const KEY = 'steadfast';

    /** Steadfast's words for where a parcel stands (lower case), in ours. */
    private const STATUSES = [
        'in_review' => CourierStatus::BOOKED,
        'pending' => CourierStatus::IN_TRANSIT,
        'delivered_approval_pending' => CourierStatus::IN_TRANSIT,
        'partial_delivered_approval_pending' => CourierStatus::IN_TRANSIT,
        'cancelled_approval_pending' => CourierStatus::IN_TRANSIT,
        'unknown_approval_pending' => CourierStatus::IN_TRANSIT,
        'hold' => CourierStatus::ON_HOLD,
        'delivered' => CourierStatus::DELIVERED,
        'partial_delivered' => CourierStatus::PARTLY_DELIVERED,
        'cancelled' => CourierStatus::CANCELLED,
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function countries(): array
    {
        return ['BD'];
    }

    public function credentialFields(): array
    {
        return ['api_key', 'secret_key'];
    }

    public function book(array $credentials, Parcel $parcel): Booking
    {
        if ($parcel->currency !== 'BDT') {
            throw new CourierFailed('refused', 'Steadfast collects taka only.');
        }
        $answer = $this->call($credentials, fn (PendingRequest $http) => $http->post('/create_order', [
            'invoice' => $parcel->invoice,
            'recipient_name' => Str::limit($parcel->name, 100, ''),
            'recipient_phone' => $this->localPhone($parcel->phone),
            'recipient_address' => Str::limit($parcel->address, 250, ''),
            'cod_amount' => self::taka($parcel->codMinor),
            'note' => $parcel->note === null ? '' : Str::limit($parcel->note, 200, ''),
        ]));
        $consignment = $answer['consignment'] ?? null;
        if (! is_array($consignment) || ! isset($consignment['consignment_id'], $consignment['tracking_code'])) {
            throw new CourierFailed('bad_answer');
        }

        return new Booking((string) $consignment['consignment_id'], (string) $consignment['tracking_code'],
            $this->ours((string) ($consignment['status'] ?? 'in_review')));
    }

    public function status(array $credentials, string $consignment): string
    {
        $answer = $this->call($credentials, fn (PendingRequest $http) => $http->get('/status_by_cid/'.rawurlencode($consignment)));

        return $this->ours((string) ($answer['delivery_status'] ?? ''));
    }

    public function readNotice(array $payload): ?array
    {
        if (($payload['notification_type'] ?? null) !== 'delivery_status' || ! isset($payload['consignment_id'], $payload['status'])) {
            return null;
        }
        $message = $payload['tracking_message'] ?? null;

        return [
            'consignment' => (string) $payload['consignment_id'],
            'status' => $this->ours((string) $payload['status']),
            'note' => is_string($message) && $message !== '' ? Str::limit($message, 250, '') : null,
        ];
    }

    // ---------------------------------------------------------------- inside

    /**
     * @param  array<string, string>  $credentials
     * @param  callable(PendingRequest): Response  $send
     * @return array<string, mixed>
     */
    private function call(array $credentials, callable $send): array
    {
        $http = Http::baseUrl(rtrim((string) config('services.steadfast.url'), '/'))
            ->withHeaders(['Api-Key' => (string) ($credentials['api_key'] ?? ''), 'Secret-Key' => (string) ($credentials['secret_key'] ?? '')])
            ->acceptJson()->asJson()->timeout((int) config('services.steadfast.timeout', 15));
        try {
            $response = $send($http);
        } catch (ConnectionException) {
            throw new CourierFailed('unreachable');
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new CourierFailed('bad_credentials');
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new CourierFailed($response->serverError() ? 'unreachable' : 'bad_answer');
        }
        if (! $response->successful() || (int) ($body['status'] ?? 200) !== 200) {
            throw new CourierFailed('refused', $this->message($body));
        }

        return $body;
    }

    /** The courier's own reason (its message, or the first field error), short. */
    private function message(array $body): ?string
    {
        $errors = $body['errors'] ?? null;
        $first = is_array($errors) ? collect($errors)->flatten()->first() : null;
        $text = is_string($first) ? $first : ($body['message'] ?? null);

        return is_string($text) ? Str::limit($text, 200) : null;
    }

    private function ours(string $theirs): string
    {
        return self::STATUSES[Str::lower(str_replace(' ', '_', trim($theirs)))] ?? CourierStatus::UNKNOWN;
    }

    /** +8801712345678 -> 01712345678 (Steadfast takes the 11-digit local number). */
    private function localPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $digits;
    }

    /** Paisa as taka with two places ("290.00"), without floats. */
    public static function taka(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
