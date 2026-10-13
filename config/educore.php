<?php

/*
 * Pengaturan operasional EduCore. Nilai rahasia tetap hanya di .env.
 */
return [
    // Masa simpan audit log dalam hari (OD-16). Minimal 30.
    'audit_retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),
];
