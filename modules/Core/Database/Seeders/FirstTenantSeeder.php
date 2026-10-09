<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Organization\CreateOrganization;
use Modules\Core\Application\Organization\NewOrganizationData;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationCategory;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\Tenant;

/**
 * Tenant pertama beserta pohon lembaganya (PRD-000 §4.3).
 *
 * Aman dijalankan berulang: bila tenant dengan kode TENANT_CODE sudah ada, seeder dilewati.
 */
class FirstTenantSeeder extends Seeder
{
    public const TENANT_CODE = 'YAYASAN';

    // [ASUMSI] Nama sementara — ganti dengan nama resmi bila sudah tersedia.
    private const TENANT_NAME = 'Yayasan (nama resmi belum ditetapkan)';

    private const PONPES_NAME = 'Pondok Pesantren';

    /** Biro/Asisten pusat (data owner, 2026-10-10). */
    private const BIROS = [
        'BIRO-PENDIDIKAN' => 'Biro Pendidikan dan Koordinator Antar Lembaga',
        'BIRO-SDM-KEU' => 'Biro SDM dan Keuangan',
        'BIRO-HUMAS' => 'Biro Humas',
        'BIRO-IT' => 'Biro IT dan Sistem',
    ];

    /** Lembaga formal per unit Pondok Pesantren (data owner, 2026-10-10). */
    private const FORMAL_PER_UNIT = [
        1 => [Jenjang::Mts, Jenjang::Ma],
        2 => [Jenjang::Mts, Jenjang::Ma],
        3 => [Jenjang::Smp, Jenjang::Smk],
    ];

    /** [ASUMSI] Lembaga nonformal ada di setiap unit (jawaban OD-01: "tiap unit"). */
    private const NONFORMAL_PER_UNIT = [Jenjang::Mda, Jenjang::Bahasa, Jenjang::AlQuran];

    public function __construct(
        private readonly CreateOrganization $createOrganization,
    ) {}

    public function run(): void
    {
        if (Tenant::query()->where('code', self::TENANT_CODE)->exists()) {
            return;
        }

        // Satu transaksi: kalau satu langkah gagal, tidak ada pohon setengah jadi.
        DB::transaction(function (): void {
            $tenant = Tenant::query()->create([
                'code' => self::TENANT_CODE,
                'name' => self::TENANT_NAME,
            ]);

            foreach (self::BIROS as $code => $name) {
                $this->node($tenant->id, null, OrganizationType::Biro, $code, $name);
            }

            $ponpes = $this->node(
                $tenant->id,
                null,
                OrganizationType::Lembaga,
                'PONPES',
                self::PONPES_NAME,
                OrganizationCategory::Pesantren,
                Jenjang::Ponpes,
            );

            foreach (self::FORMAL_PER_UNIT as $unitNumber => $formalJenjangs) {
                $unit = $this->node($tenant->id, $ponpes->id, OrganizationType::Unit, "PONPES-U{$unitNumber}", "Unit {$unitNumber}");

                foreach ($formalJenjangs as $jenjang) {
                    $this->lembagaInUnit($tenant->id, $unit->id, $unitNumber, $jenjang, OrganizationCategory::Formal);
                }

                foreach (self::NONFORMAL_PER_UNIT as $jenjang) {
                    $this->lembagaInUnit($tenant->id, $unit->id, $unitNumber, $jenjang, OrganizationCategory::Nonformal);
                }
            }
        });
    }

    private function lembagaInUnit(
        string $tenantId,
        string $unitId,
        int $unitNumber,
        Jenjang $jenjang,
        OrganizationCategory $category,
    ): Organization {
        return $this->node(
            $tenantId,
            $unitId,
            OrganizationType::Lembaga,
            "U{$unitNumber}-{$jenjang->value}",
            "{$jenjang->label()} Unit {$unitNumber}",
            $category,
            $jenjang,
        );
    }

    private function node(
        string $tenantId,
        ?string $parentId,
        OrganizationType $type,
        string $code,
        string $name,
        ?OrganizationCategory $category = null,
        ?Jenjang $jenjang = null,
    ): Organization {
        return $this->createOrganization->handle(new NewOrganizationData(
            tenantId: $tenantId,
            parentId: $parentId,
            type: $type,
            code: $code,
            name: $name,
            category: $category,
            jenjang: $jenjang,
        ));
    }
}
