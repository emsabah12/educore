<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditTrailService
{
    /**
     * Mencatat aktivitas otentikasi secara langsung ke PostgreSQL.
     * Menggunakan native UUID v7 yang diwarisi dari spesifikasi kernel.
     */
    public function writeAuthAuditLog(string $tenantUuid, ?string $userUuid, string $eventType, array $metadata = []): void
    {
        // Memanfaatkan Query Builder Laravel untuk performa tinggi pada operasi insert-only
        DB::table('auth_audits')->insert([
            // ID menggunakan UUID v7. Jika macro kernel Anda terdaftar, bisa menggunakan DB::raw("gen_random_uuid()") atau generator php kernel.
            'id'          => (string) Str::uuid(), // Ganti dengan Core\Support\Uuid\UuidV7::generate() sesuai implementasi kernel Anda
            'tenant_uuid' => $tenantUuid,
            'user_uuid'   => $userUuid,
            'event_type'  => strtoupper($eventType),
            'metadata'    => json_encode($metadata),
            'ip_address'  => request()->ip(),
            'user_agent'  => request()->userAgent(),
            'created_at'  => now()
        ]);
    }
}
