<?php

namespace Modules\HR\Application\Payroll;

use Modules\HR\Domain\Payroll\PayComponentBasis;
use Modules\HR\Domain\Payroll\PayComponentKind;

/**
 * Isi awal komponen gaji untuk yayasan baru (PRD-001 §16.1, [ASUMSI]). Bisa diubah nama atau
 * dinonaktifkan Admin SDM; kode, jenis, dan cara hitung tetap.
 */
final class DefaultPayComponents
{
    /**
     * @return array<string, array{name: string, kind: PayComponentKind, basis: PayComponentBasis}> kode => isi
     */
    public static function items(): array
    {
        return [
            'GAPOK' => ['name' => 'Gaji pokok', 'kind' => PayComponentKind::Earning, 'basis' => PayComponentBasis::Monthly],
            'TUNJ-JABATAN' => ['name' => 'Tunjangan jabatan', 'kind' => PayComponentKind::Earning, 'basis' => PayComponentBasis::Monthly],
            'TUNJ-WALI-KELAS' => ['name' => 'Tunjangan wali kelas', 'kind' => PayComponentKind::Earning, 'basis' => PayComponentBasis::Monthly],
            'TRANSPORT' => ['name' => 'Uang transport', 'kind' => PayComponentKind::Earning, 'basis' => PayComponentBasis::PerPresentDay],
            'BPJS' => ['name' => 'Iuran BPJS', 'kind' => PayComponentKind::Deduction, 'basis' => PayComponentBasis::Monthly],
        ];
    }
}
