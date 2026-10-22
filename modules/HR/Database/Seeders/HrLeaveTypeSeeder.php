<?php

namespace Modules\HR\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Leave\DefaultLeaveTypes;
use Modules\HR\Application\Leave\ManageLeaveTypes;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Isi awal jenis cuti untuk setiap yayasan (PRD-001 §13.2).
 *
 * Aman dijalankan berulang dan di production: hanya menambah kode yang BELUM ada. Jenis yang sudah
 * diubah atau dinonaktifkan Admin SDM tidak disentuh.
 */
class HrLeaveTypeSeeder extends Seeder
{
    public function __construct(
        private readonly ManageLeaveTypes $leaveTypes,
    ) {}

    public function run(): void
    {
        foreach (Tenant::query()->orderBy('code')->pluck('id') as $tenantId) {
            $existing = LeaveType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->pluck('code')->all();

            foreach (DefaultLeaveTypes::items() as $code => $item) {
                if (! in_array($code, $existing, true)) {
                    $this->leaveTypes->create((string) $tenantId, $code, $item['name'], $item['annual_quota'] !== null, $item['annual_quota'], $item['max_days']);
                }
            }
        }
    }
}
