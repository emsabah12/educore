<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Support\Facades\DB;

/**
 * Pesan "NIK sudah terdaftar" untuk modul HR (PRD-001 §4.5, OD-HR-01 revisi 2026-10-23).
 *
 * Pemilik NIK hanya disebut bila ia anggota yayasan yang sama; selain itu pesannya umum,
 * agar keberadaan orang di yayasan lain tidak bocor. [ASUMSI, disetujui owner 2026-10-23]
 */
final class EmployeeNik
{
    public function takenMessage(string $tenantId, string $ownerPersonId): string
    {
        $owner = $ownerPersonId === '' ? null : DB::table('persons as p')
            ->join('memberships as m', 'm.person_id', '=', 'p.id')
            ->leftJoin('hr_employees as e', 'e.membership_id', '=', 'm.id')
            ->where('m.tenant_id', $tenantId)
            ->where('p.id', $ownerPersonId)
            ->first(['p.name', 'e.id as employee_id']);

        if ($owner === null) {
            return 'NIK ini sudah terdaftar di EduCore atas nama orang lain. Periksa kembali; bila NIK benar, hubungi admin platform.';
        }

        return $owner->employee_id === null
            ? "NIK ini milik {$owner->name}, anggota yayasan yang belum menjadi pegawai. Jadikan pegawai lewat Tambah pegawai → \"Anggota yayasan\"."
            : "NIK ini sudah tercatat atas nama {$owner->name} (pegawai).";
    }
}
