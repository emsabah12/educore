<?php

namespace Tests\Support;

use Modules\Finance\Application\Online\MidtransException;
use Modules\Finance\Application\Online\MidtransGateway;

/**
 * Midtrans tiruan untuk test (PRD-006 §12): tanpa panggilan ke internet. Status per order diatur test lewat
 * `$statuses`; tanda tangan dihitung dengan rumus asli memakai SERVER_KEY tiruan.
 */
final class FakeMidtransGateway implements MidtransGateway
{
    public const SERVER_KEY = 'SB-Mid-server-tiruan';

    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var array<string, array<string, mixed>> */
    public array $statuses = [];

    public ?MidtransException $failNext = null;

    public function isConfigured(): bool
    {
        return true;
    }

    public function isProduction(): bool
    {
        return false;
    }

    public function createTransaction(array $payload): array
    {
        $this->throwIfFailing();
        $this->created[] = $payload;
        $orderId = (string) ($payload['transaction_details']['order_id'] ?? '');

        return ['token' => 'tok-'.$orderId, 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok-'.$orderId];
    }

    public function status(string $orderId): array
    {
        $this->throwIfFailing();

        return $this->statuses[$orderId] ?? ['order_id' => $orderId, 'transaction_status' => 'pending', 'status_code' => '201'];
    }

    public function cancel(string $orderId): void
    {
        $this->throwIfFailing();
        $this->cancelled[] = $orderId;
    }

    public function verifySignature(string $orderId, string $statusCode, string $grossAmount, string $signature): bool
    {
        return hash_equals(self::sign($orderId, $statusCode, $grossAmount), $signature);
    }

    public static function sign(string $orderId, string $statusCode, string $grossAmount): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.self::SERVER_KEY);
    }

    private function throwIfFailing(): void
    {
        if ($this->failNext !== null) {
            $exception = $this->failNext;
            $this->failNext = null;

            throw $exception;
        }
    }
}
