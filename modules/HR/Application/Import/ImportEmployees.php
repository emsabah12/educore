<?php

namespace Modules\HR\Application\Import;

use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Domain\Employee\Exceptions\DuplicatePersonException;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;

/**
 * Menyimpan file impor pegawai (HR-1e-b, PRD-001 §6.2).
 *
 * - File dibaca dan diperiksa ULANG (data bisa berubah sejak pratinjau).
 * - Hanya bila tidak ada baris Salah; peringatan orang ganda wajib dikonfirmasi (OD-HR-05).
 * - Semua baris dalam SATU transaksi memakai CreateEmployee (aturan & audit sama dengan form):
 *   bila satu baris gagal, tidak ada pegawai yang tersimpan.
 */
final class ImportEmployees
{
    public function __construct(
        private readonly EmployeeImportReader $reader,
        private readonly EmployeeImportValidator $validator,
        private readonly CreateEmployee $createEmployee,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  'xlsx'|'csv'  $extension
     * @return int jumlah pegawai yang tersimpan
     *
     * @throws ImportFileException
     * @throws ImportSaveException
     */
    public function handle(string $tenantId, string $path, string $extension, bool $warningsConfirmed): int
    {
        $preview = $this->validator->validate($tenantId, $this->reader->read($path, $extension));

        if (! $preview->canSave()) {
            throw ImportSaveException::hasErrors($preview->count(EmployeeImportRow::ERROR));
        }

        if ($preview->hasWarnings() && ! $warningsConfirmed) {
            throw ImportSaveException::warningsNotConfirmed();
        }

        $rows = [];

        foreach ($preview->rows as $row) {
            // Tidak mungkin kosong bila tidak ada kesalahan; dijaga agar tidak ada baris yang terlewat diam-diam.
            if ($row->data === null) {
                throw ImportSaveException::rowFailed($row->line, 'Data baris ini tidak lengkap.');
            }

            $rows[] = ['line' => $row->line, 'data' => $row->data];
        }

        return DB::transaction(function () use ($tenantId, $rows): int {
            foreach ($rows as $row) {
                try {
                    $this->createEmployee->handle($row['data']);
                } catch (EmployeeException|DuplicatePersonException $exception) {
                    // Melempar keluar dari closure membatalkan seluruh transaksi.
                    throw ImportSaveException::rowFailed($row['line'], $exception->getMessage());
                }
            }

            $this->audit->record(
                HrAuditAction::EMPLOYEES_IMPORTED,
                changes: ['jumlah' => count($rows)],
                tenantId: $tenantId,
            );

            return count($rows);
        });
    }
}
