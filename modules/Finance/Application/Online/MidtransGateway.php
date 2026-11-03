<?php

namespace Modules\Finance\Application\Online;

/**
 * Akses ke Midtrans (PRD-006 §5, §12). Implementasi produksi: HttpMidtransGateway (HTTP client Laravel, tanpa library
 * tambahan). Test memakai Http::fake atau implementasi tiruan, tanpa panggilan ke internet.
 */
interface MidtransGateway
{
    /** Kunci server terpasang di .env. */
    public function isConfigured(): bool;

    /** true = akun produksi; false = sandbox (uji). */
    public function isProduction(): bool;

    /**
     * Buat transaksi Snap. $payload = body Snap (transaction_details, item_details, customer_details,
     * enabled_payments, expiry, callbacks).
     *
     * @param  array<string, mixed>  $payload
     * @return array{token: string, redirect_url: string}
     *
     * @throws MidtransException
     */
    public function createTransaction(array $payload): array;

    /**
     * Status transaksi terkini (API status Midtrans).
     *
     * @return array<string, mixed>
     *
     * @throws MidtransException
     */
    public function status(string $orderId): array;

    /**
     * Batalkan transaksi yang belum dibayar.
     *
     * @throws MidtransException
     */
    public function cancel(string $orderId): void;

    /**
     * Cocokkan tanda tangan notifikasi: SHA512(order_id + status_code + gross_amount + ServerKey).
     */
    public function verifySignature(string $orderId, string $statusCode, string $grossAmount, string $signature): bool;
}
