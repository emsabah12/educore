<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Notification\Contracts;

interface NotificationChannelInterface
{
    /**
     * Mengirimkan pesan notifikasi secara terstandarisasi.
     * 
     * @param string $tenantId ID lembaga asal pengirim.
     * @param string $recipient Alamat tujuan pengiriman (Email/No HP/UUID).
     * @param string $body Konten isi pesan utama.
     * @param array $options Konfigurasi tambahan seperti title atau attachment payload.
     * @return array Mengembalikan array terstruktur yang berisi ['success' => bool, 'metadata' => array, 'error' => ?string]
     */
    public function send(string $tenantId, string $recipient, string $body, array $options = []): array;
}
