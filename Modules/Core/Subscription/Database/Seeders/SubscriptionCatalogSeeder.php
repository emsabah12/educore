<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;

/**
 * Katalog awal Subscription — idempotent (`updateOrCreate` berdasar
 * `code` unik), aman dijalankan berulang tanpa duplikasi, mengikuti
 * pola `HrAuthorizationCatalogSeeder`/`AuthorizationCatalogSeeder`.
 *
 * "Custom Role" SENGAJA hanya dimasukkan ke fitur bawaan paket
 * Enterprise — tenant Basic/Pro cuma bisa mengaktifkannya lewat
 * add-on terpisah, mendemonstrasikan pola gating berbasis fitur
 * (bukan permission satu-satu) sesuai keputusan PRD.
 */
final class SubscriptionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $customRolesFeature = SubscriptionFeature::query()->updateOrCreate(
            ['code' => 'custom_roles'],
            [
                'name' => 'Custom Role',
                'description' => 'Tenant dapat membuat role kustom sendiri (mis. "Wali Kelas") di luar role sistem bawaan.',
            ],
        );

        $hrModuleFeature = SubscriptionFeature::query()->updateOrCreate(
            ['code' => 'hr_module'],
            [
                'name' => 'Modul HR',
                'description' => 'Akses ke modul Human Resources — Manajemen Pegawai, riwayat Employment, dan katalog Jenis Employment.',
            ],
        );

        $basicPlan = SubscriptionPlan::query()->updateOrCreate(
            ['code' => 'basic'],
            [
                'name' => 'Basic',
                'description' => 'Paket dasar untuk institusi kecil.',
                'grace_period_days' => 30,
                'is_active' => true,
            ],
        );

        $proPlan = SubscriptionPlan::query()->updateOrCreate(
            ['code' => 'pro'],
            [
                'name' => 'Pro',
                'description' => 'Paket menengah dengan kapasitas lebih besar, termasuk Modul HR.',
                'grace_period_days' => 30,
                'is_active' => true,
            ],
        );

        $enterprisePlan = SubscriptionPlan::query()->updateOrCreate(
            ['code' => 'enterprise'],
            [
                'name' => 'Enterprise',
                'description' => 'Paket penuh untuk yayasan/institusi besar, termasuk Custom Role dan Modul HR.',
                'grace_period_days' => 60,
                'is_active' => true,
            ],
        );

        // Hanya Enterprise yang punya custom_roles sebagai fitur
        // BAWAAN paket — Basic/Pro harus lewat add-on terpisah.
        DB::table('plan_features')->insertOrIgnore([
            'plan_id' => $enterprisePlan->id,
            'feature_id' => $customRolesFeature->id,
        ]);

        // Modul HR bawaan untuk Pro dan Enterprise — Basic harus
        // lewat add-on terpisah (lihat 'hr-module-addon' di bawah).
        // Ini contoh KEDUA dari pola gating berbasis fitur (bukan
        // permission satu-satu) di PRD, kali ini men-gate SELURUH
        // modul, bukan satu kemampuan spesifik seperti custom_roles.
        DB::table('plan_features')->insertOrIgnore([
            'plan_id' => $proPlan->id,
            'feature_id' => $hrModuleFeature->id,
        ]);

        DB::table('plan_features')->insertOrIgnore([
            'plan_id' => $enterprisePlan->id,
            'feature_id' => $hrModuleFeature->id,
        ]);

        Addon::query()->updateOrCreate(
            ['code' => 'custom-roles-addon'],
            [
                'name' => 'Custom Role Add-on',
                'description' => 'Aktifkan kemampuan membuat role kustom untuk paket Basic/Pro.',
                'feature_id' => $customRolesFeature->id,
                'is_active' => true,
            ],
        );

        Addon::query()->updateOrCreate(
            ['code' => 'hr-module-addon'],
            [
                'name' => 'Modul HR Add-on',
                'description' => 'Aktifkan akses Modul HR untuk paket Basic.',
                'feature_id' => $hrModuleFeature->id,
                'is_active' => true,
            ],
        );
    }
}
