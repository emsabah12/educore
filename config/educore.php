<?php

/*
 * Pengaturan operasional EduCore. Nilai rahasia tetap hanya di .env.
 */
return [
    // Masa simpan audit log dalam hari (OD-16). Minimal 30.
    'audit_retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    // Zona waktu yayasan untuk tanggal bisnis ("hari ini"), mis. tanggal mulai kerja tidak boleh
    // di masa depan. Jam server/database tetap UTC (PRD-001 §4.3, [ASUMSI]).
    'timezone' => (string) env('EDUCORE_TIMEZONE', 'Asia/Jakarta'),
];
