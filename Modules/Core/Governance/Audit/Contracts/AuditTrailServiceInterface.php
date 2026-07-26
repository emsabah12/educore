<?php

declare(strict_types=1);

namespace Modules\Core\Governance\Audit\Contracts;

interface AuditTrailServiceInterface
{
    /**
     * Rekam jejak aktivitas operasional aplikasi ke dalam media penyimpanan immutable.
     *
     * @param string $eventType Jenis event (contoh: 'auth.login_success', 'auth.failed')
     * @param string $description Penjelasan naratif mengenai tindakan tersebut
     * @param string|null $tenantId UUID lembaga/tenant yang bersangkutan
     * @param string|null $userId UUID pengguna yang melakukan aksi
     * @param array|null $payload Metadata tambahan (data request mentah, parameter sebelum/sesudah)
     * @return void
     */
    public function log(
        string $eventType,
        string $description,
        ?string $tenantId = null,
        ?string $userId = null,
        ?array $payload = null
    ): void;
}
