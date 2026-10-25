<?php

namespace Modules\HR\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Payroll\DefaultPayComponents;
use Modules\HR\Application\Payroll\ManagePayComponents;
use Modules\HR\Domain\Payroll\PayComponent;

/**
 * Isi awal komponen gaji untuk setiap yayasan (PRD-001 §16.1).
 *
 * Aman dijalankan berulang dan di production: hanya menambah kode yang BELUM ada. Komponen yang sudah
 * diubah atau dinonaktifkan Admin SDM tidak disentuh.
 */
class HrPayComponentSeeder extends Seeder
{
    public function __construct(
        private readonly ManagePayComponents $components,
    ) {}

    public function run(): void
    {
        foreach (Tenant::query()->orderBy('code')->pluck('id') as $tenantId) {
            $existing = PayComponent::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->pluck('code')->all();

            foreach (DefaultPayComponents::items() as $code => $item) {
                if (! in_array($code, $existing, true)) {
                    $this->components->create((string) $tenantId, $code, $item['name'], $item['kind'], $item['basis']);
                }
            }
        }
    }
}
