<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the Midtrans Core API calls the till needs: create a QRIS charge, read a
 * transaction's status, cancel it, and verify notification signatures.
 *
 * @see https://docs.midtrans.com/reference/qris
 */
final class MidtransClient
{
    public function isConfigured(): bool
    {
        return $this->serverKey() !== '';
    }

    /**
     * @return array{transaction_id: string, qr_string: string|null, qr_url: string|null, expires_at: string|null}
     *
     * @throws MidtransException
     */
    public function chargeQris(string $orderId, int $grossAmount): array
    {
        $body = $this->send('post', '/v2/charge', [
            'payment_type' => 'qris',
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $grossAmount],
            'qris' => ['acquirer' => config('services.midtrans.qris_acquirer', 'gopay')],
            'custom_expiry' => [
                'expiry_duration' => (int) config('services.midtrans.qris_expiry_minutes', 15),
                'unit' => 'minute',
            ],
        ]);

        $qrUrl = null;
        foreach ((array) ($body['actions'] ?? []) as $action) {
            if (is_array($action) && ($action['name'] ?? null) === 'generate-qr-code') {
                $qrUrl = is_string($action['url'] ?? null) ? $action['url'] : null;
            }
        }

        return [
            'transaction_id' => (string) ($body['transaction_id'] ?? ''),
            'qr_string' => is_string($body['qr_string'] ?? null) ? $body['qr_string'] : null,
            'qr_url' => $qrUrl,
            'expires_at' => is_string($body['expiry_time'] ?? null) ? $body['expiry_time'] : null,
        ];
    }

    /**
     * @return array<string, mixed> transaction_status, fraud_status, gross_amount, …
     *
     * @throws MidtransException
     */
    public function status(string $orderId): array
    {
        return $this->send('get', '/v2/'.rawurlencode($orderId).'/status');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MidtransException
     */
    public function cancel(string $orderId): array
    {
        return $this->send('post', '/v2/'.rawurlencode($orderId).'/cancel');
    }

    /** signature_key = SHA512(order_id + status_code + gross_amount + server_key) */
    public function signatureMatches(string $orderId, string $statusCode, string $grossAmount, string $signature): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $expected = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey());

        return hash_equals($expected, strtolower($signature));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws MidtransException
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        if (! $this->isConfigured()) {
            throw new MidtransException('Midtrans belum dikonfigurasi (MIDTRANS_SERVER_KEY kosong).');
        }

        try {
            $response = $method === 'get'
                ? $this->http()->get($path)
                : $this->http()->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new MidtransException('Tidak bisa terhubung ke Midtrans. Periksa koneksi internet.', previous: $e);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $statusCode = (string) ($body['status_code'] ?? $response->status());

        // Midtrans reports API errors in the body's status_code, sometimes with HTTP 200.
        if ($response->failed() || ! str_starts_with($statusCode, '2')) {
            Log::warning('Midtrans request failed', [
                'path' => $path,
                'http_status' => $response->status(),
                'status_code' => $statusCode,
                'message' => $body['status_message'] ?? null,
            ]);

            throw new MidtransException(
                'Midtrans menolak permintaan: '.(is_string($body['status_message'] ?? null) ? $body['status_message'] : 'HTTP '.$response->status()),
                $statusCode,
            );
        }

        return $body;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withBasicAuth($this->serverKey(), '')
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->connectTimeout(5);
    }

    private function baseUrl(): string
    {
        return config('services.midtrans.is_production')
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }

    private function serverKey(): string
    {
        $key = config('services.midtrans.server_key');

        return is_string($key) ? trim($key) : '';
    }
}
