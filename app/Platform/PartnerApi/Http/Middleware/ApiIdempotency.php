<?php

namespace App\Platform\PartnerApi\Http\Middleware;

use App\Platform\PartnerApi\Exceptions\ApiException;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes sent with an Idempotency-Key header happen once: a repeat with the
 * same key and the same body gets the first answer again (header
 * Idempotent-Replayed: true); the same key with another body is refused.
 * Answers are kept for a day, per API key.
 */
class ApiIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $apiKey = $request->attributes->get('partner_api_key');

        if ($idempotencyKey === '' || $apiKey === null || $request->isMethodSafe()) {
            return $next($request);
        }

        $idempotencyKey = Str::limit($idempotencyKey, 100, '');
        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        $stored = DB::table('api_idempotency')
            ->where('api_key_id', $apiKey->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->where('created_at', '>', now()->subDay())
            ->first();

        if ($stored !== null) {
            if (! hash_equals($stored->request_hash, $hash)) {
                throw ApiException::idempotencyMismatch();
            }

            return response($stored->response, (int) $stored->status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
        }

        $response = $next($request);

        // Keep answers the client can rely on; server errors may be retried.
        if ($response->getStatusCode() < 500) {
            DB::table('api_idempotency')->where('api_key_id', $apiKey->getKey())->where('idempotency_key', $idempotencyKey)->delete();

            try {
                DB::table('api_idempotency')->insert([
                    'id' => (string) Str::ulid(),
                    'api_key_id' => $apiKey->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $hash,
                    'status' => $response->getStatusCode(),
                    'response' => (string) $response->getContent(),
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // The same request finished at the same moment: its answer is kept.
            }
        }

        return $response;
    }
}
