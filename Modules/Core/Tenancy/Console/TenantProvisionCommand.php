<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Services\TenantManager;
use Throwable;

final class TenantProvisionCommand extends Command
{
    protected $signature = 'core:tenant-provision
                            {--name= : Nama institusi/sekolah baru}
                            {--subdomain= : Subdomain unik untuk sekolah tersebut}
                            {--domain= : Custom domain opsional (misal: sekolah.sch.id)}';

    protected $description =
    'Otomatisasi pembuatan dan provisioning sekolah (tenant) baru pada EduCore Kernel';

    public function handle(
        TenantManager $tenantManager,
    ): int {
        $name = $this->normalizeOption(
            $this->option('name'),
        );

        $subdomain = $this->normalizeOption(
            $this->option('subdomain'),
        );

        $domain = $this->normalizeOption(
            $this->option('domain'),
        );

        if ($name === null) {
            $name = $this->normalizeOption(
                $this->ask(
                    'Masukkan Nama Sekolah/Tenant',
                ),
            );
        }

        if ($subdomain === null) {
            $subdomain = $this->normalizeOption(
                $this->ask(
                    'Masukkan Subdomain (Contoh: smp01)',
                ),
            );
        }

        if ($name === null || $subdomain === null) {
            $this->error(
                'Gagal: Nama dan subdomain tidak boleh kosong.',
            );

            return Command::FAILURE;
        }

        $this->info(
            sprintf(
                'Sedang memproses provisioning untuk: %s (%s)...',
                $name,
                $subdomain,
            ),
        );

        try {
            $tenant = $tenantManager->createTenant([
                'name' => $name,
                'subdomain' => $subdomain,
                'domain' => $domain,
                'is_active' => true,
                'settings' => [
                    'provisioned_via' => 'Artisan CLI',
                    'created_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (Throwable $exception) {
            /*
             * Detail exception diteruskan ke Laravel reporting pipeline,
             * tetapi tidak diekspos langsung melalui output CLI.
             */
            report($exception);

            $this->error(
                'Gagal melakukan provisioning tenant. Periksa log aplikasi.',
            );

            return Command::FAILURE;
        }

        $this->newLine();

        $this->table(
            [
                'Key Data',
                'Value',
            ],
            [
                [
                    'ID (UUID v7)',
                    (string) $tenant['id'],
                ],
                [
                    'Nama Sekolah',
                    (string) $tenant['name'],
                ],
                [
                    'Subdomain',
                    (string) $tenant['subdomain'],
                ],
                [
                    'Status',
                    (bool) $tenant['is_active']
                        ? 'ACTIVE'
                        : 'INACTIVE',
                ],
            ],
        );

        $this->info(
            'Sukses: Tenant baru berhasil ditambahkan ke cluster.',
        );

        return Command::SUCCESS;
    }

    private function normalizeOption(
        mixed $value,
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }
}
