<?php

namespace Modules\HR\Application\Leave;

/**
 * Isi awal jenis cuti untuk yayasan baru (PRD-001 §13.2, [ASUMSI]). Semuanya bisa diubah atau
 * dinonaktifkan Admin SDM; kodenya tetap.
 */
final class DefaultLeaveTypes
{
    /**
     * @return array<string, array{name: string, annual_quota: int|null, max_days: int|null}> kode => isi
     */
    public static function items(): array
    {
        return [
            'CUTI-TAHUNAN' => ['name' => 'Cuti tahunan', 'annual_quota' => 12, 'max_days' => null],
            'SAKIT' => ['name' => 'Sakit', 'annual_quota' => null, 'max_days' => null],
            'IZIN' => ['name' => 'Izin keperluan pribadi', 'annual_quota' => null, 'max_days' => null],
            'MELAHIRKAN' => ['name' => 'Cuti melahirkan', 'annual_quota' => null, 'max_days' => 90],
        ];
    }
}
