<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\TenantManager;
use Exception;

class TenantProvisionCommand extends Command
{
    /**
     * Nama dan signature dari Artisan Command CLI.
     * Format: php artisan core:tenant-provision --name="Sekolah A" --subdomain="sekolaha"
     */
    protected $signature = 'core:tenant-provision 
                            {--name= : Nama institusi/sekolah baru} 
                            {--subdomain= : Subdomain unik untuk sekolah tersebut} 
                            {--domain= : Custom domain opsional (misal: sekolah.sch.id)}';

    /**
     * Deskripsi command yang muncul di list php artisan.
     */
    protected $description = 'Otomatisasi pembuatan dan provisioning sekolah (tenant) baru pada EduCore Kernel';

    /**
     * Eksekusi thin command dengan mendelegasikan tugas ke TenantManager.
     */
    public function handle(TenantManager $tenantManager): int
    {
        $name = $this->option('name');
        $subdomain = $this->option('subdomain');
        $domain = $this->option('domain');

        // Interaksi interaktif jika user tidak mengisi opsi di terminal
        if (!$name) {
            $name = $this->ask('Masukkan Nama Sekolah/Tenant');
        }
        if (!$subdomain) {
            $subdomain = $this->ask('Masukkan Subdomain (Contoh: smp01)');
        }

        if (empty($name) || empty($subdomain)) {
            $this->error('Gagal: Nama dan Subdomain tidak boleh kosong!');
            return Command::FAILURE;
        }

        $this->info("Sedang memproses provisioning untuk: {$name} ({$subdomain})...");

        try {
            $tenant = $tenantManager->createTenant([
                'name' => $name,
                'subdomain' => $subdomain,
                'domain' => $domain,
                'settings' => [
                    'provisioned_via' => 'Artisan CLI',
                    'created_at' => now()->toIso8601String()
                ]
            ]);

            $this->newLine();
            $this->table(
                ['Key Data', 'Value'],
                [
                    ['ID (UUID v7)', $tenant->id],
                    ['Nama Sekolah', $tenant->name],
                    ['Subdomain', $tenant->subdomain],
                    ['Status', $tenant->is_active ? 'ACTIVE' : 'INACTIVE']
                ]
            );

            $this->info('🚀 Sukses: Tenant baru berhasil ditambahkan ke cluster!');
            return Command::SUCCESS;

        } catch (Exception $exception) {
            $this->error("Gagal melakukan provisioning: {$exception->getMessage()}");
            return Command::FAILURE;
        }
    }
}