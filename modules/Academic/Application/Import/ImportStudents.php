<?php

namespace Modules\Academic\Application\Import;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Student\CreateStudent;
use Modules\Academic\Domain\Student\Exceptions\StudentCheckFailed;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;

/**
 * Menyimpan file impor siswa (PRD-002 AC-1e-b), pola sama dengan impor pegawai (HR-1e-b):
 *
 * - File dibaca dan diperiksa ULANG (data bisa berubah sejak pratinjau).
 * - Hanya bila tidak ada baris Salah; peringatan orang ganda wajib dikonfirmasi.
 * - Semua baris dalam SATU transaksi memakai CreateStudent (aturan & audit sama dengan form Tambah
 *   siswa, termasuk orang tua/wali dan kelas): bila satu baris gagal, tidak ada siswa yang tersimpan.
 */
final class ImportStudents
{
    public function __construct(
        private readonly StudentImportReader $reader,
        private readonly StudentImportValidator $validator,
        private readonly CreateStudent $createStudent,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  'xlsx'|'csv'  $extension
     * @return array{saved: int, existing: int} jumlah siswa tersimpan, dan berapa di antaranya siswa lama
     *
     * @throws ImportSaveException
     */
    public function handle(string $tenantId, Organization $organization, bool $canManageClasses, string $path, string $extension, bool $warningsConfirmed): array
    {
        $preview = $this->validator->validate($tenantId, $organization, $canManageClasses, $this->reader->read($path, $extension));

        if (! $preview->canSave()) {
            throw ImportSaveException::hasErrors($preview->count(StudentImportRow::ERROR));
        }

        if ($preview->hasWarnings() && ! $warningsConfirmed) {
            throw ImportSaveException::warningsNotConfirmed();
        }

        $rows = [];
        $existing = 0;

        foreach ($preview->rows as $row) {
            // Tidak mungkin kosong bila tidak ada kesalahan; dijaga agar tidak ada baris yang terlewat diam-diam.
            if ($row->data === null) {
                throw ImportSaveException::rowFailed($row->line, 'Data baris ini tidak lengkap.');
            }

            if ($row->mode === StudentImportRow::MODE_EXISTING) {
                $existing++;
            }

            $rows[] = ['line' => $row->line, 'data' => $row->data];
        }

        $saved = DB::transaction(function () use ($tenantId, $organization, $rows, $existing): int {
            foreach ($rows as $row) {
                try {
                    $this->createStudent->handle($row['data']);
                } catch (StudentException $exception) {
                    // Melempar keluar dari closure membatalkan seluruh transaksi.
                    throw ImportSaveException::rowFailed($row['line'], $exception->getMessage());
                } catch (StudentCheckFailed $exception) {
                    $messages = array_values($exception->errors);
                    $duplicate = $exception->duplicateMessage();

                    if ($duplicate !== null) {
                        $messages[] = $duplicate;
                    }

                    throw ImportSaveException::rowFailed($row['line'], implode(' ', $messages));
                }
            }

            $this->audit->record(
                AcademicAuditAction::STUDENTS_IMPORTED,
                changes: ['lembaga' => $organization->code, 'jumlah' => count($rows), 'siswa_lama' => $existing],
                organizationId: $organization->id,
                tenantId: $tenantId,
            );

            return count($rows);
        });

        return ['saved' => $saved, 'existing' => $existing];
    }
}
