<?php

namespace Modules\Finance\Application\Online;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien Midtrans lewat HTTP client Laravel (PRD-006 §5, §12). Kunci dari `config('services.midtrans')` (.env).
 *
 * - Snap: POST {app}/snap/v1/transactions → token & redirect_url.
 * - API status / batal: GET {api}/v2/{order_id}/status, POST {api}/v2/{order_id}/cancel.
 * - Otentikasi: HTTP Basic, username = Server Key, sandi kosong.
 */
final class HttpMidtransGateway implements MidtransGateway
{
    private const TIMEOUT_SECONDS = 15;

    public function isConfigured(): bool
    {
        return $this->serverKey() !== '';
    }

    public function isProduction(): bool
    {
        return (bool) config('services.midtrans.is_production', false);
    }

    public function createTransaction(array $payload): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->post($this->appUrl().'/snap/v1/transactions', $payload));
        $token = $response->json('token');
        $redirect = $response->json('redirect_url');

        if (! is_string($token) || ! is_string($redirect)) {
            Log::warning('Midtrans: jawaban Snap tanpa token', ['status' => $response->status()]);

            throw MidtransException::rejected();
        }

        return ['token' => $token, 'redirect_url' => $redirect];
    }

    public function status(string $orderId): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get($this->apiUrl().'/v2/'.rawurlencode($orderId).'/status'));

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return $body;
    }

    public function cancel(string $orderId): void
    {
        $this->send(fn (PendingRequest $request): Response => $request->post($this->apiUrl().'/v2/'.rawurlencode($orderId).'/cancel'));
    }

    public function verifySignature(string $orderId, string $statusCode, string $grossAmount, string $signature): bool
    {
        if (! $this->isConfigured() || $signature === '') {
            return false;
        }

        return hash_equals(hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey()), strtolower($signature));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws MidtransException
     */
    private function send(callable $call): Response
    {
        if (! $this->isConfigured()) {
            throw MidtransException::notConfigured();
        }

        try {
            $response = $call(Http::withBasicAuth($this->serverKey(), '')->acceptJson()->asJson()->timeout(self::TIMEOUT_SECONDS));
        } catch (ConnectionException $exception) {
            Log::warning('Midtrans tidak bisa dihubungi', ['error' => $exception->getMessage()]);

            throw MidtransException::unreachable();
        }

        if ($response->serverError()) {
            Log::warning('Midtrans error server', ['status' => $response->status()]);

            throw MidtransException::unreachable();
        }

        // API status/batal Midtrans menjawab HTTP 200 dengan `status_code` di body; 404 = transaksi belum ada.
        if ($response->clientError()) {
            Log::warning('Midtrans menolak permintaan', ['status' => $response->status(), 'message' => $response->json('error_messages') ?? $response->json('status_message')]);

            throw MidtransException::rejected();
        }

        return $response;
    }

    private function serverKey(): string
    {
        return trim((string) config('services.midtrans.server_key', ''));
    }

    private function appUrl(): string
    {
        return $this->isProduction() ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
    }

    private function apiUrl(): string
    {
        return $this->isProduction() ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }
}
