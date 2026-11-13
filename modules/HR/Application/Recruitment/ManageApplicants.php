<?php

namespace Modules\HR\Application\Recruitment;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Person\Gender;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Document\DocumentFileStore;
use Modules\HR\Application\Employee\PersonalData;
use Modules\HR\Domain\Document\Exceptions\DocumentException;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Recruitment\ApplicantStage;
use Modules\HR\Domain\Recruitment\Exceptions\RecruitmentException;
use Modules\HR\Domain\Recruitment\StepAction;
use Modules\HR\Domain\Recruitment\VacancyStatus;
use stdClass;
use Throwable;

/**
 * Pelamar & tahap seleksi (PRD-001 §20.1, HR-9a; OD-HR-19, OD-HR-20).
 *
 * - Ditambah Admin SDM hanya pada lowongan **Dibuka**; data pelamar boleh diubah selama belum Diterima/Tidak lolos.
 * - Berkas lamaran opsional: satu PDF/JPG/PNG maks. 2 MB di disk privat; mengganti berkas menghapus berkas lama.
 * - Tahap tetap: Berkas → Tes/wawancara → Diterima / Tidak lolos. Tidak lolos & kembalikan wajib alasan 5–300.
 *   Nilai 0–100 opsional, catatan maks. 500.
 * - Catatan wawancara boleh ditambah penilai lembaga (Kepala Lembaga) pada tahap Tes/wawancara; Admin pada tahap
 *   yang belum final.
 * - Audit tanpa nama/kontak pelamar dan tanpa isi catatan.
 *
 * Hak akses & cakupan lembaga dicek pemanggil (controller).
 */
final class ManageApplicants
{
    public const NAME_MIN = 2;

    public const NAME_MAX = 200;

    public const EMAIL_MAX = 150;

    public const MAJOR_MAX = 150;

    public const NOTE_MAX = 500;

    public const REASON_MIN = 5;

    public const REASON_MAX = 300;

    public const FILE_MAX_KILOBYTES = 2 * 1024;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentFileStore $files,
    ) {}

    public static function pathFor(string $tenantId, string $applicantId, string $extension): string
    {
        return "tenants/{$tenantId}/hr/recruitment/{$applicantId}.{$extension}";
    }

    /**
     * @param  array{name: string, gender: string, phone: string, email: string, education_level: string, education_major: string, note: string}  $input
     * @return string id pelamar
     *
     * @throws RecruitmentException
     */
    public function add(string $tenantId, string $vacancyId, array $input, ?UploadedFile $file, string $membershipId): string
    {
        $values = $this->validate($input);
        $vacancy = $this->vacancy($tenantId, $vacancyId);

        if ($vacancy->status !== VacancyStatus::Open->value) {
            throw RecruitmentException::make('form', 'Pelamar hanya bisa ditambahkan ke lowongan yang sedang dibuka.');
        }

        $id = (string) Str::uuid7();
        $stored = $this->storeFile($tenantId, $id, $file);

        try {
            DB::transaction(function () use ($tenantId, $vacancyId, $vacancy, $id, $values, $stored, $membershipId): void {
                DB::table('hr_applicants')->insert($values + $stored + [
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'vacancy_id' => $vacancyId,
                    'stage' => ApplicantStage::Screening->value,
                    'created_by_membership_id' => $membershipId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->audit->record(
                    HrAuditAction::APPLICANT_ADDED,
                    HrAuditAction::SUBJECT_APPLICANT,
                    $id,
                    ['lowongan' => $vacancyId, 'berkas' => $stored['storage_path'] !== null],
                    organizationId: (string) $vacancy->organization_id,
                    tenantId: $tenantId,
                );
            });
        } catch (Throwable $exception) {
            if ($stored['storage_path'] !== null) {
                $this->files->delete($stored['storage_path']);
            }

            throw $exception;
        }

        return $id;
    }

    /**
     * @param  array{name: string, gender: string, phone: string, email: string, education_level: string, education_major: string, note: string}  $input
     *
     * @throws RecruitmentException
     */
    public function update(string $tenantId, string $vacancyId, string $applicantId, array $input, ?UploadedFile $file): void
    {
        $values = $this->validate($input);
        $applicant = $this->find($tenantId, $vacancyId, $applicantId);

        if (ApplicantStage::from((string) $applicant->stage)->isFinal()) {
            throw RecruitmentException::make('form', 'Data pelamar yang sudah Diterima/Tidak lolos tidak bisa diubah.');
        }

        $stored = $file === null ? [] : $this->storeFile($tenantId, $applicantId, $file);
        $oldPath = $applicant->storage_path === null ? null : (string) $applicant->storage_path;

        try {
            DB::transaction(function () use ($tenantId, $applicantId, $values, $stored, $applicant): void {
                DB::table('hr_applicants')->where('id', $applicantId)->update($values + $stored + ['updated_at' => now()]);

                $changed = array_keys(array_filter([
                    'nama' => $values['name'] !== (string) $applicant->name,
                    'jenis_kelamin' => $values['gender'] !== (string) $applicant->gender,
                    'hp' => $values['phone'] !== $applicant->phone,
                    'email' => $values['email'] !== $applicant->email,
                    'pendidikan' => $values['education_level'] !== $applicant->education_level || $values['education_major'] !== $applicant->education_major,
                    'catatan' => $values['note'] !== $applicant->note,
                    'berkas' => $stored !== [],
                ]));

                if ($changed !== []) {
                    $this->audit->record(HrAuditAction::APPLICANT_UPDATED, HrAuditAction::SUBJECT_APPLICANT, $applicantId, ['diubah' => $changed], tenantId: $tenantId);
                }
            });
        } catch (Throwable $exception) {
            if (($stored['storage_path'] ?? null) !== null && $stored['storage_path'] !== $oldPath) {
                $this->files->delete($stored['storage_path']);
            }

            throw $exception;
        }

        // Berkas lama dihapus sesudah tersimpan (nama file bisa sama bila ekstensinya sama).
        if ($oldPath !== null && ($stored['storage_path'] ?? null) !== null && $stored['storage_path'] !== $oldPath) {
            $this->files->delete($oldPath);
        }
    }

    /**
     * Mencatat aksi seleksi. `$asManager` = Admin SDM / Admin Yayasan; selain itu hanya catatan di tahap Tes/wawancara.
     *
     * @throws RecruitmentException
     */
    public function step(string $tenantId, string $vacancyId, string $applicantId, string $action, string $score, string $note, string $membershipId, bool $asManager): void
    {
        $actionCase = StepAction::tryFrom($action) ?? throw RecruitmentException::make('form', 'Aksi seleksi tidak dikenal.');
        $score = trim($score);

        if ($score !== '' && (preg_match('/^\d{1,3}$/', $score) !== 1 || (int) $score > 100)) {
            throw RecruitmentException::make('score', 'Nilai harus bilangan bulat 0–100.');
        }

        $note = trim((string) preg_replace('/[ \t]+/u', ' ', str_replace("\r\n", "\n", $note)));
        $needsReason = $actionCase === StepAction::Reject || $actionCase === StepAction::Reopen;

        if ($needsReason && (mb_strlen($note) < self::REASON_MIN || mb_strlen($note) > self::REASON_MAX)) {
            throw RecruitmentException::make('note', 'Isi alasan '.self::REASON_MIN.'–'.self::REASON_MAX.' karakter.');
        }

        if ($actionCase === StepAction::Note && $note === '') {
            throw RecruitmentException::make('note', 'Isi catatan.');
        }

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw RecruitmentException::make('note', 'Catatan paling banyak '.self::NOTE_MAX.' karakter.');
        }

        if (! $asManager && $actionCase !== StepAction::Note) {
            throw RecruitmentException::make('form', 'Anda hanya bisa menambah catatan wawancara.');
        }

        DB::transaction(function () use ($tenantId, $vacancyId, $applicantId, $actionCase, $score, $note, $membershipId, $asManager): void {
            $applicant = $this->find($tenantId, $vacancyId, $applicantId);
            $stage = ApplicantStage::from((string) $applicant->stage);

            if ($applicant->employee_id !== null && $actionCase !== StepAction::Note) {
                throw RecruitmentException::make('form', 'Pelamar ini sudah dijadikan pegawai, jadi tahapnya tidak bisa diubah.');
            }

            [$allowed, $next] = match ($actionCase) {
                StepAction::PassScreening => [$stage === ApplicantStage::Screening, ApplicantStage::Interview],
                StepAction::Accept => [$stage === ApplicantStage::Interview, ApplicantStage::Accepted],
                StepAction::Reject => [! $stage->isFinal(), ApplicantStage::Rejected],
                StepAction::Reopen => [$stage->isFinal(), ApplicantStage::Interview],
                StepAction::Note => [$asManager ? ! $stage->isFinal() : $stage === ApplicantStage::Interview, $stage],
            };

            if (! $allowed) {
                throw RecruitmentException::make('form', match ($actionCase) {
                    StepAction::PassScreening => 'Pelamar ini tidak sedang di tahap Seleksi berkas.',
                    StepAction::Accept => 'Pelamar hanya bisa diterima dari tahap Tes/wawancara.',
                    StepAction::Reject => 'Pelamar ini sudah punya hasil akhir.',
                    StepAction::Reopen => 'Hanya hasil akhir (Diterima/Tidak lolos) yang bisa dikembalikan.',
                    StepAction::Note => 'Catatan wawancara hanya bisa ditambah pada tahap Tes/wawancara.',
                });
            }

            DB::table('hr_applicant_steps')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'applicant_id' => $applicantId,
                'action' => $actionCase->value,
                'stage' => $stage->value,
                'score' => $score === '' ? null : (int) $score,
                'note' => $note === '' ? null : $note,
                'membership_id' => $membershipId,
                'created_at' => now(),
            ]);

            if ($next !== $stage) {
                DB::table('hr_applicants')->where('id', $applicantId)->update(['stage' => $next->value, 'updated_at' => now()]);
            }

            $this->audit->record(
                HrAuditAction::APPLICANT_STEP,
                HrAuditAction::SUBJECT_APPLICANT,
                $applicantId,
                ['lowongan' => $vacancyId, 'aksi' => $actionCase->value, 'dari' => $stage->value, 'ke' => $next->value, 'nilai' => $score !== ''],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @return array{path: string, name: string, mime: string}
     *
     * @throws RecruitmentException
     */
    public function attachment(string $tenantId, string $vacancyId, string $applicantId): array
    {
        $applicant = $this->find($tenantId, $vacancyId, $applicantId, lock: false);

        if ($applicant->storage_path === null || ! $this->files->exists((string) $applicant->storage_path)) {
            throw RecruitmentException::make('file', 'Berkas lamaran tidak ditemukan.');
        }

        $this->audit->record(HrAuditAction::APPLICANT_FILE_DOWNLOADED, HrAuditAction::SUBJECT_APPLICANT, $applicantId, ['lowongan' => $vacancyId], tenantId: $tenantId);

        return [
            'path' => $this->files->absolutePath((string) $applicant->storage_path),
            'name' => (string) $applicant->original_name,
            'mime' => (string) $applicant->mime_type,
        ];
    }

    /**
     * @throws RecruitmentException
     */
    private function vacancy(string $tenantId, string $vacancyId): stdClass
    {
        $vacancy = Str::isUuid($vacancyId)
            ? DB::table('hr_vacancies')->where('tenant_id', $tenantId)->where('id', $vacancyId)->first()
            : null;

        return $vacancy ?? throw RecruitmentException::make('form', 'Lowongan tidak ditemukan.');
    }

    /**
     * @throws RecruitmentException
     */
    private function find(string $tenantId, string $vacancyId, string $applicantId, bool $lock = true): stdClass
    {
        $applicant = Str::isUuid($vacancyId) && Str::isUuid($applicantId)
            ? DB::table('hr_applicants')
                ->where('tenant_id', $tenantId)
                ->where('vacancy_id', $vacancyId)
                ->where('id', $applicantId)
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->first()
            : null;

        return $applicant ?? throw RecruitmentException::make('form', 'Pelamar tidak ditemukan.');
    }

    /**
     * @return array{storage_path: string|null, original_name: string|null, mime_type: string|null, byte_size: int|null, sha256: string|null}
     *
     * @throws RecruitmentException
     */
    private function storeFile(string $tenantId, string $applicantId, ?UploadedFile $file): array
    {
        if ($file === null) {
            return ['storage_path' => null, 'original_name' => null, 'mime_type' => null, 'byte_size' => null, 'sha256' => null];
        }

        try {
            $info = $this->files->inspect($file);
        } catch (DocumentException $exception) {
            throw RecruitmentException::make('file', $exception->getMessage());
        }

        if ($info['size'] > self::FILE_MAX_KILOBYTES * 1024) {
            throw RecruitmentException::make('file', 'Ukuran berkas maksimal '.(self::FILE_MAX_KILOBYTES / 1024).' MB.');
        }

        // Nama berkas diberi akhiran acak supaya penggantian tidak menimpa berkas lama sebelum tersimpan.
        $path = self::pathFor($tenantId, $applicantId.'-'.Str::lower(Str::random(8)), $info['extension']);

        try {
            $this->files->put($file, $path);
        } catch (DocumentException $exception) {
            throw RecruitmentException::make('file', $exception->getMessage());
        }

        return ['storage_path' => $path, 'original_name' => $info['name'], 'mime_type' => $info['mime'], 'byte_size' => $info['size'], 'sha256' => $info['sha256']];
    }

    /**
     * @param  array{name: string, gender: string, phone: string, email: string, education_level: string, education_major: string, note: string}  $input
     * @return array{name: string, gender: string, phone: string|null, email: string|null, education_level: string|null, education_major: string|null, note: string|null}
     *
     * @throws RecruitmentException
     */
    private function validate(array $input): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $input['name']));

        if (mb_strlen($name) < self::NAME_MIN || mb_strlen($name) > self::NAME_MAX) {
            throw RecruitmentException::make('name', 'Nama pelamar '.self::NAME_MIN.'–'.self::NAME_MAX.' karakter.');
        }

        $gender = Gender::tryFrom($input['gender']) ?? throw RecruitmentException::make('gender', 'Pilih jenis kelamin.');
        $phone = PersonalData::phone($input['phone']);

        if ($phone !== null && preg_match(PersonalData::PHONE_PATTERN, $phone) !== 1) {
            throw RecruitmentException::make('phone', 'Nomor HP 8–19 angka, boleh diawali +.');
        }

        $email = trim($input['email']);

        if ($email !== '' && (mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw RecruitmentException::make('email', 'Isi alamat email yang benar.');
        }

        $level = trim($input['education_level']);

        if ($level !== '' && EducationLevel::tryFrom($level) === null) {
            throw RecruitmentException::make('education_level', 'Pilih pendidikan terakhir.');
        }

        $major = trim($input['education_major']);

        if (mb_strlen($major) > self::MAJOR_MAX) {
            throw RecruitmentException::make('education_major', 'Jurusan paling banyak '.self::MAJOR_MAX.' karakter.');
        }

        $note = trim(str_replace("\r\n", "\n", $input['note']));

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw RecruitmentException::make('note', 'Catatan paling banyak '.self::NOTE_MAX.' karakter.');
        }

        return [
            'name' => $name,
            'gender' => $gender->value,
            'phone' => $phone,
            'email' => $email === '' ? null : mb_strtolower($email),
            'education_level' => $level === '' ? null : $level,
            'education_major' => $major === '' ? null : $major,
            'note' => $note === '' ? null : $note,
        ];
    }
}
