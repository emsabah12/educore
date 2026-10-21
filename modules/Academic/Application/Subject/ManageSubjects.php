<?php

namespace Modules\Academic\Application\Subject;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Subject\Exceptions\SubjectException;
use Modules\Academic\Domain\Subject\Subject;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationType;

/**
 * Mata pelajaran per lembaga (PRD-002 §16 AC-3a): tambah, ubah, nonaktifkan/aktifkan kembali, hapus.
 *
 * Aturan [OWNER, 2026-10-21]:
 * - Nama wajib (maks. 100), unik per lembaga tanpa beda huruf besar/kecil.
 * - Kode singkat opsional (maks. 20, disimpan huruf besar), unik per lembaga.
 * - Rentang tingkat: keduanya kosong = semua tingkat; bila diisi keduanya 1–12 dan min ≤ maks.
 * - Nonaktif = tidak bisa dipilih untuk kelas; hapus hanya bila belum pernah dipakai di kelas (AC-3b).
 *
 * Hak akses (academic.classes.manage di lembaga mapel) dicek pemanggil lewat Gate.
 */
final class ManageSubjects
{
    public const NAME_MAX_LENGTH = 100;

    public const CODE_MAX_LENGTH = 20;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws SubjectException
     */
    public function create(string $tenantId, string $organizationId, SubjectData $data): Subject
    {
        $this->assertValidInput($data);

        return $this->transaction($data, function () use ($tenantId, $organizationId, $data): Subject {
            $organization = $this->activeLembaga($tenantId, $organizationId);
            $this->assertUnique($organization->id, $data, null);

            $subject = new Subject;
            $subject->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'name' => $data->name,
                'code' => $data->code,
                'grade_min' => $data->gradeMin,
                'grade_max' => $data->gradeMax,
            ]);
            $subject->save();

            $this->audit->record(
                AcademicAuditAction::SUBJECT_CREATED,
                AcademicAuditAction::SUBJECT_SUBJECT,
                $subject->id,
                [
                    'nama' => $subject->name,
                    'kode' => $subject->code,
                    'lembaga' => $organization->code,
                    'tingkat' => self::gradeLabel($subject->grade_min, $subject->grade_max),
                ],
                organizationId: $organization->id,
                tenantId: $tenantId,
            );

            return $subject;
        });
    }

    /**
     * @return list<string> field yang berubah
     *
     * @throws SubjectException
     */
    public function update(string $tenantId, string $subjectId, SubjectData $data): array
    {
        $this->assertValidInput($data);

        return $this->transaction($data, function () use ($tenantId, $subjectId, $data): array {
            $subject = $this->lock($tenantId, $subjectId);
            $this->assertUnique($subject->organization_id, $data, $subject->id);

            $subject->fill([
                'name' => $data->name,
                'code' => $data->code,
                'grade_min' => $data->gradeMin,
                'grade_max' => $data->gradeMax,
            ]);
            $changed = array_keys($subject->getDirty());

            if ($changed === []) {
                return [];
            }

            $subject->save();

            $this->audit->record(
                AcademicAuditAction::SUBJECT_UPDATED,
                AcademicAuditAction::SUBJECT_SUBJECT,
                $subject->id,
                ['nama' => $subject->name, 'field_diubah' => $changed],
                organizationId: $subject->organization_id,
                tenantId: $tenantId,
            );

            return $changed;
        });
    }

    /**
     * @throws SubjectException
     */
    public function setActive(string $tenantId, string $subjectId, bool $active): Subject
    {
        return DB::transaction(function () use ($tenantId, $subjectId, $active): Subject {
            $subject = $this->lock($tenantId, $subjectId);

            if ($subject->is_active === $active) {
                return $subject;
            }

            $subject->is_active = $active;
            $subject->save();

            $this->audit->record(
                $active ? AcademicAuditAction::SUBJECT_REACTIVATED : AcademicAuditAction::SUBJECT_DEACTIVATED,
                AcademicAuditAction::SUBJECT_SUBJECT,
                $subject->id,
                ['nama' => $subject->name],
                organizationId: $subject->organization_id,
                tenantId: $tenantId,
            );

            return $subject;
        });
    }

    /**
     * Hanya mapel yang belum pernah dipakai di kelas; selain itu nonaktifkan.
     *
     * @throws SubjectException
     */
    public function delete(string $tenantId, string $subjectId): string
    {
        return DB::transaction(function () use ($tenantId, $subjectId): string {
            // Pemeriksaan "sudah dipakai di kelas" ditambahkan bersama tabel mapel kelas (AC-3b).
            $subject = $this->lock($tenantId, $subjectId);
            $name = $subject->name;
            $subject->delete();

            $this->audit->record(
                AcademicAuditAction::SUBJECT_DELETED,
                AcademicAuditAction::SUBJECT_SUBJECT,
                $subject->id,
                ['nama' => $name],
                organizationId: $subject->organization_id,
                tenantId: $tenantId,
            );

            return $name;
        });
    }

    /**
     * "Semua tingkat", "Tingkat 7" atau "Tingkat 7–9".
     */
    public static function gradeLabel(?int $min, ?int $max): string
    {
        if ($min === null || $max === null) {
            return 'Semua tingkat';
        }

        return $min === $max ? "Tingkat {$min}" : "Tingkat {$min}–{$max}";
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function activeLembaga(string $tenantId, string $organizationId): Organization
    {
        $organization = Str::isUuid($organizationId)
            ? Organization::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($organizationId)->first()
            : null;

        if (! $organization instanceof Organization) {
            throw SubjectException::make('organization_id', 'Lembaga tidak ditemukan di yayasan ini.');
        }

        if ($organization->type !== OrganizationType::Lembaga) {
            throw SubjectException::make('organization_id', 'Mata pelajaran hanya bisa dibuat di lembaga (bukan unit atau biro).');
        }

        if (! $organization->isActive()) {
            throw SubjectException::make('organization_id', 'Lembaga sudah nonaktif.');
        }

        return $organization;
    }

    private function lock(string $tenantId, string $subjectId): Subject
    {
        $subject = Str::isUuid($subjectId)
            ? Subject::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($subjectId)->lockForUpdate()->first()
            : null;

        if (! $subject instanceof Subject) {
            throw SubjectException::make('form', 'Mata pelajaran tidak ditemukan di yayasan ini.');
        }

        return $subject;
    }

    private function assertUnique(string $organizationId, SubjectData $data, ?string $exceptId): void
    {
        $base = Subject::query()->withoutGlobalScope('tenant')->where('organization_id', $organizationId);

        if ($exceptId !== null) {
            $base->whereKeyNot($exceptId);
        }

        if ((clone $base)->whereRaw('lower(name) = lower(?)', [$data->name])->exists()) {
            throw SubjectException::duplicateName($data->name);
        }

        if ($data->code !== null && (clone $base)->whereRaw('upper(code) = upper(?)', [$data->code])->exists()) {
            throw SubjectException::duplicateCode($data->code);
        }
    }

    private function assertValidInput(SubjectData $data): void
    {
        if ($data->name === '' || mb_strlen($data->name) > self::NAME_MAX_LENGTH) {
            throw SubjectException::make('name', 'Nama mata pelajaran wajib diisi, maksimal '.self::NAME_MAX_LENGTH.' karakter.');
        }

        if ($data->code !== null && mb_strlen($data->code) > self::CODE_MAX_LENGTH) {
            throw SubjectException::make('code', 'Kode maksimal '.self::CODE_MAX_LENGTH.' karakter.');
        }

        if (($data->gradeMin === null) !== ($data->gradeMax === null)) {
            throw SubjectException::make('grade_min', 'Isi tingkat dari dan sampai, atau kosongkan keduanya untuk semua tingkat.');
        }

        if ($data->gradeMin !== null && $data->gradeMax !== null) {
            if ($data->gradeMin < 1 || $data->gradeMin > 12 || $data->gradeMax < 1 || $data->gradeMax > 12) {
                throw SubjectException::make('grade_min', 'Tingkat harus angka 1–12.');
            }

            if ($data->gradeMin > $data->gradeMax) {
                throw SubjectException::make('grade_max', 'Tingkat sampai tidak boleh lebih kecil dari tingkat dari.');
            }
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function transaction(SubjectData $data, Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            // Dua operator menyimpan nama/kode yang sama bersamaan.
            throw SubjectException::make('name', "Nama atau kode mata pelajaran {$data->name} baru saja dipakai. Periksa lagi lalu simpan.");
        }
    }
}
