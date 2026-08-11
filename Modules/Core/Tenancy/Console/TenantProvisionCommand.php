<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Services\TenantProvisioningService;
use Throwable;

final class TenantProvisionCommand extends Command
{
    protected $signature = 'core:tenant-provision
                            {--name= : Nama institusi/sekolah baru}
                            {--subdomain= : Subdomain unik untuk sekolah tersebut}
                            {--domain= : Custom domain opsional (misal: sekolah.sch.id)}
                            {--admin-user-id= : UUIDv7 User yang menjadi initial tenant admin}';

    protected $description =
    'Otomatisasi pembuatan tenant beserta initial tenant administrator';

    public function handle(
        TenantProvisioningService $tenantProvisioningService,
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

        $adminUserId = $this->normalizeOption(
            $this->option('admin-user-id'),
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

        if ($adminUserId === null) {
            $adminUserId = $this->normalizeOption(
                $this->ask(
                    'Masukkan UUIDv7 User untuk initial tenant admin',
                ),
            );
        }

        if (
            $name === null
            || $subdomain === null
            || $adminUserId === null
        ) {
            $this->error(
                'Gagal: Nama, subdomain, dan admin user id tidak boleh kosong.',
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
            $result = $tenantProvisioningService->provision(
                [
                    'name' => $name,
                    'subdomain' => $subdomain,
                    'domain' => $domain,
                    'is_active' => true,
                    'settings' => [
                        'provisioned_via' => 'Artisan CLI',
                        'created_at' => now()->toIso8601String(),
                    ],
                ],
                $adminUserId,
            );
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

        $tenant = $result['tenant'];
        $initialAdmin = $result['initial_admin'];

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
                [
                    'Initial Admin User ID',
                    $initialAdmin['user_id'],
                ],
                [
                    'Initial Admin Membership ID',
                    $initialAdmin['membership_id'],
                ],
            ],
        );

        $this->info(
            'Sukses: Tenant dan initial administrator berhasil diprovisioning.',
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
