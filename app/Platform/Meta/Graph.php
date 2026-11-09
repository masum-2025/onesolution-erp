<?php

namespace App\Platform\Meta;

use App\Platform\Meta\Exceptions\MetaException;
use App\Platform\Meta\Models\MetaApp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only door to Meta's Graph API. Every call made with a token signs it
 * with the app's secret (appsecret_proof), so a stolen token alone is of no
 * use. Tokens and secrets never go into logs or error messages: a failure
 * comes back as a MetaException of one kind (see MetaException).
 */
class Graph
{
    /** Address of Meta's sign-in dialog where a person picks the pages to connect. */
    public function dialogUrl(MetaApp $app, string $redirectUri, string $state, array $scopes): string
    {
        return rtrim((string) config('meta.dialog_url'), '/').'/'.config('meta.graph_version').'/dialog/oauth?'.http_build_query([
            'client_id' => $app->app_id,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', $scopes),
            'response_type' => 'code',
        ]);
    }

    /** The code Meta sent back, as a long-lived person token. */
    public function personToken(MetaApp $app, string $code, string $redirectUri): string
    {
        $short = (string) ($this->call(fn () => $this->http()->get($this->url('oauth/access_token'), [
            'client_id' => $app->app_id,
            'client_secret' => $app->app_secret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]))['access_token'] ?? '');
        if ($short === '') {
            throw MetaException::graph(MetaException::REJECTED);
        }

        $long = (string) ($this->call(fn () => $this->http()->get($this->url('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $app->app_id,
            'client_secret' => $app->app_secret,
            'fb_exchange_token' => $short,
        ]))['access_token'] ?? '');

        return $long !== '' ? $long : $short;
    }

    /** The app's name at Meta; fails when the id and secret do not belong together. */
    public function appName(string $appId, string $secret): string
    {
        try {
            $data = $this->call(fn () => $this->http()->get($this->url($appId), ['fields' => 'name', 'access_token' => $appId.'|'.$secret]));
        } catch (MetaException $e) {
            throw $e->errorCode() === MetaException::UNREACHABLE ? $e : MetaException::appCheckFailed();
        }

        return (string) ($data['name'] ?? $appId);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(MetaApp $app, string $path, string $token, array $query = []): array
    {
        return $this->call(fn () => $this->http()->get($this->url($path), [...$query, ...$this->signed($app, $token)]));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(MetaApp $app, string $path, string $token, array $body = []): array
    {
        return $this->call(fn () => $this->http()->asForm()->post($this->url($path), [...$body, ...$this->signed($app, $token)]));
    }

    /**
     * A file sent as multipart (photos are uploaded, never fetched by Meta from a link).
     *
     * @param  array<string, scalar>  $fields
     * @return array<string, mixed>
     */
    public function upload(MetaApp $app, string $path, string $token, string $contents, string $filename, array $fields = []): array
    {
        return $this->call(fn () => $this->http()->attach('source', $contents, $filename)
            ->post($this->url($path), [...array_map('strval', $fields), ...$this->signed($app, $token)]));
    }

    // ---------------------------------------------------------------- inside

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('meta.timeout'))->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('meta.graph_url'), '/').'/'.config('meta.graph_version').'/'.ltrim($path, '/');
    }

    /**
     * @return array{access_token: string, appsecret_proof: string}
     */
    private function signed(MetaApp $app, string $token): array
    {
        return ['access_token' => $token, 'appsecret_proof' => hash_hmac('sha256', $token, (string) $app->app_secret)];
    }

    /**
     * Sends one call; a dropped connection is "unreachable", an error from Meta one of the kinds.
     *
     * @param  callable(): Response  $request
     * @return array<string, mixed>
     */
    private function call(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw MetaException::graph(MetaException::UNREACHABLE);
        }

        $data = (array) $response->json();
        if ($response->successful() && ! isset($data['error'])) {
            return $data;
        }

        $error = (array) ($data['error'] ?? []);
        $code = (int) ($error['code'] ?? 0);
        $kind = match (true) {
            in_array($code, [102, 190, 463, 467], true) => MetaException::TOKEN_INVALID,
            $code === 10 || ($code >= 200 && $code <= 299) => MetaException::PERMISSION_MISSING,
            in_array($code, [4, 17, 32, 341, 368, 613], true) => MetaException::RATE_LIMITED,
            $response->serverError() => MetaException::UNREACHABLE,
            default => MetaException::REJECTED,
        };

        throw MetaException::graph($kind, (string) ($error['error_user_msg'] ?? ''));
    }
}
