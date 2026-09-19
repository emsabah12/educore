<?php

declare(strict_types=1);

namespace Modules\HR\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Throwable;

/**
 * §Langkah 3 (self-service leave gap remediation) — jalur backfill
 * SATU KALI untuk Employee yang sudah ada SEBELUM
 * WorkspaceEmployeeProvisioningService mulai meng-assign role
 * `employee` secara otomatis (lihat catatan arsitektur di
 * WorkspaceEmployeeProvisioningService) — Employee baru sudah
 * otomatis ter-cover lewat situ, command ini murni untuk mengejar
 * ketertinggalan Employee lama.
 *
 * Pola sama persis dengan
 * `Modules\Core\Tenancy\Console\TenantBackfillDefaultOrganizationCommand`.
 *
 * Aman dijalankan berulang kali (idempotent) — Employee yang sudah
 * punya role `employee` otomatis dilewati, bukan cuma saat command
 * ini pertama kali dijalankan.
 */
final class BackfillEmployeeSelfServiceRoleCommand extends Command
{
    /**
     * §Sama persis dengan konstanta lokal di
     * WorkspaceEmployeeProvisioningService — lihat catatan di sana
     * soal kenapa nilainya diduplikasi, bukan mereferensikan
     * EmployeeSelfServiceRoleSeeder::EMPLOYEE_ROLE secara langsung.
     */
    private const EMPLOYEE_ROLE_NAME = 'employee';

    protected $signature = 'hr:backfill-employee-self-service-role
                            {--dry-run : Tampilkan Employee yang akan diproses tanpa benar-benar meng-assign apa pun}';

    protected $description =
        'Backfill role employee (kapabilitas self-service Cuti) untuk Employee aktif yang sudah ada sebelum auto-assign diterapkan';

    public function handle(
        MembershipRoleRepositoryInterface $membershipRoleRepository,
    ): int {
        $dryRun = (bool) $this->option(
            'dry-run',
        );

        $employeeRole = Role::query()
            ->whereNull('tenant_id')
            ->where('name', self::EMPLOYEE_ROLE_NAME)
            ->first();

        if ($employeeRole === null) {
            $this->error(
                'Canonical employee role is unavailable. Run EmployeeSelfServiceRoleSeeder first.',
            );

            return Command::FAILURE;
        }

        $employeeRoleId = (string) $employeeRole->getKey();

        // Hanya Employee AKTIF (tidak soft-deleted) dengan Membership
        // AKTIF -- Membership tidak aktif akan ditolak assignRole()
        // sendiri, disaring lebih awal di sini supaya baris seperti
        // itu tidak ikut terhitung sebagai kandidat yang "akan
        // diproses" saat dry-run.
        $candidates = DB::table('employees')
            ->join(
                'memberships',
                'memberships.id',
                '=',
                'employees.membership_id',
            )
            ->whereNull('employees.deleted_at')
            ->where('memberships.status', 'ACTIVE')
            ->orderBy('employees.created_at')
            ->get(
                [
                    'employees.id as employee_id',
                    'employees.membership_id',
                    'employees.tenant_id',
                ],
            );

        if ($candidates->isEmpty()) {
            $this->info(
                'Tidak ada Employee aktif ditemukan. Tidak ada yang diproses.',
            );

            return Command::SUCCESS;
        }

        $this->info(
            sprintf(
                '%sMemproses %d Employee aktif...',
                $dryRun ? '[DRY RUN] ' : '',
                $candidates->count(),
            ),
        );

        $assignedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $rows = [];

        foreach ($candidates as $candidate) {
            $alreadyGranted = DB::table('membership_roles')
                ->where('membership_id', (string) $candidate->membership_id)
                ->where('role_id', $employeeRoleId)
                ->exists();

            if ($alreadyGranted) {
                $skippedCount++;
                $rows[] = [$candidate->employee_id, 'DILEWATI (sudah punya role employee)'];

                continue;
            }

            if ($dryRun) {
                $assignedCount++;
                $rows[] = [$candidate->employee_id, 'AKAN DI-ASSIGN'];

                continue;
            }

            try {
                $membershipRoleRepository->assignRole(
                    (string) $candidate->membership_id,
                    (string) $candidate->tenant_id,
                    $employeeRoleId,
                );
            } catch (Throwable $exception) {
                report($exception);

                $failedCount++;
                $rows[] = [$candidate->employee_id, 'GAGAL — lihat log aplikasi'];

                continue;
            }

            $assignedCount++;
            $rows[] = [$candidate->employee_id, 'DI-ASSIGN'];
        }

        $this->newLine();

        $this->table(
            ['Employee ID', 'Hasil'],
            $rows,
        );

        $this->newLine();

        $this->info(
            sprintf(
                '%sSelesai: %d %s, %d dilewati (sudah punya role), %d gagal.',
                $dryRun ? '[DRY RUN] ' : '',
                $assignedCount,
                $dryRun ? 'akan di-assign' : 'di-assign',
                $skippedCount,
                $failedCount,
            ),
        );

        return $failedCount > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
