<?php

namespace Modules\HR\Application\Audit;

/**
 * Aksi modul HR yang dicatat di audit log (PRD-001 §7), dengan label untuk halaman Log audit.
 * Label didaftarkan ke Core lewat AuditAction::registerLabels() di HRServiceProvider.
 */
final class HrAuditAction
{
    public const CATALOG_CREATED = 'hr.catalog_created';

    public const CATALOG_RENAMED = 'hr.catalog_renamed';

    /** Pilihan katalog selain nama diubah, mis. "punya masa berlaku" jenis dokumen (HR-2a). */
    public const CATALOG_UPDATED = 'hr.catalog_updated';

    public const CATALOG_DEACTIVATED = 'hr.catalog_deactivated';

    public const CATALOG_ACTIVATED = 'hr.catalog_activated';

    public const EMPLOYEE_CREATED = 'hr.employee_created';

    public const EMPLOYEE_UPDATED = 'hr.employee_updated';

    public const STATUS_CHANGED = 'hr.status_changed';

    public const PLACEMENT_ADDED = 'hr.placement_added';

    public const PLACEMENT_ENDED = 'hr.placement_ended';

    public const PLACEMENT_TRANSFERRED = 'hr.placement_transferred';

    public const PLACEMENT_PRIMARY_CHANGED = 'hr.placement_primary_changed';

    public const EMPLOYMENT_ENDED = 'hr.employment_ended';

    public const EMPLOYEE_REHIRED = 'hr.employee_rehired';

    /** Satu catatan ringkasan per impor; tiap pegawai tetap tercatat EMPLOYEE_CREATED (PRD-001 §6.2). */
    public const EMPLOYEES_IMPORTED = 'hr.employees_imported';

    public const LEAVE_TYPE_CREATED = 'hr.leave_type_created';

    public const LEAVE_TYPE_UPDATED = 'hr.leave_type_updated';

    public const LEAVE_TYPE_DEACTIVATED = 'hr.leave_type_deactivated';

    public const LEAVE_TYPE_REACTIVATED = 'hr.leave_type_reactivated';

    public const HOLIDAY_ADDED = 'hr.holiday_added';

    public const HOLIDAY_UPDATED = 'hr.holiday_updated';

    public const HOLIDAY_REMOVED = 'hr.holiday_removed';

    public const LEAVE_REQUESTED = 'hr.leave_requested';

    public const LEAVE_WITHDRAWN = 'hr.leave_withdrawn';

    public const LEAVE_APPROVED = 'hr.leave_approved';

    public const LEAVE_REJECTED = 'hr.leave_rejected';

    /** Dicatat Admin SDM atas nama pegawai, langsung disetujui (HR-3d). */
    public const LEAVE_RECORDED = 'hr.leave_recorded';

    /** Cuti yang sudah disetujui dibatalkan Admin SDM (HR-3d). */
    public const LEAVE_CANCELLED = 'hr.leave_cancelled';

    /** Jatah cuti disesuaikan Admin SDM (HR-3d-b). */
    public const LEAVE_ADJUSTED = 'hr.leave_adjusted';

    /** Pegawai absen masuk sendiri (HR-4a). */
    public const ATTENDANCE_CHECKED_IN = 'hr.attendance_checked_in';

    /** Pegawai absen pulang sendiri (HR-4a). */
    public const ATTENDANCE_CHECKED_OUT = 'hr.attendance_checked_out';

    /** Petugas mengisi/mengubah/menghapus presensi pegawai di lembar presensi (HR-4b). */
    public const ATTENDANCE_RECORDED = 'hr.attendance_recorded';

    /** Dokumen pegawai (HR-2a): tambah, ubah isian, batalkan, unduh. Isi dokumen tidak dicatat. */
    public const DOCUMENT_ADDED = 'hr.document_added';

    public const DOCUMENT_UPDATED = 'hr.document_updated';

    public const DOCUMENT_VOIDED = 'hr.document_voided';

    public const DOCUMENT_DOWNLOADED = 'hr.document_downloaded';

    public const SUBJECT_DOCUMENT = 'hr_document';

    /** Catatan disiplin dicatat (HR-7a): pegawai, jenis, tanggal, ada lampiran — tanpa isi pelanggaran. */
    public const DISCIPLINE_RECORDED = 'hr.discipline_recorded';

    /** Catatan disiplin dicabut (HR-7a): pegawai, jenis — tanpa alasan. */
    public const DISCIPLINE_REVOKED = 'hr.discipline_revoked';

    public const SUBJECT_DISCIPLINE = 'hr_discipline_record';

    /** Checklist offboarding dibuat (HR-7b): pegawai, tanggal berhenti, jumlah item. */
    public const OFFBOARDING_STARTED = 'hr.offboarding_started';

    /** Item checklist offboarding diubah (HR-7b): pegawai, kode item, status — tanpa catatan. */
    public const OFFBOARDING_TASK_UPDATED = 'hr.offboarding_task_updated';

    public const SUBJECT_OFFBOARDING = 'hr_offboarding';

    /** Riwayat pelatihan (HR-8a): pegawai & jenis — tanpa isi catatan/alasan. */
    public const TRAINING_RECORDED = 'hr.training_recorded';

    public const TRAINING_SUBMITTED = 'hr.training_submitted';

    public const TRAINING_VERIFIED = 'hr.training_verified';

    public const TRAINING_REJECTED = 'hr.training_rejected';

    public const TRAINING_CANCELLED = 'hr.training_cancelled';

    public const TRAINING_WITHDRAWN = 'hr.training_withdrawn';

    public const SUBJECT_TRAINING = 'hr_training';

    /** Titik lokasi absen lembaga (HR-4d): lembaga & radius, tanpa koordinat. */
    public const WORK_LOCATION_SET = 'hr.work_location_set';

    public const WORK_LOCATION_REMOVED = 'hr.work_location_removed';

    public const SUBJECT_WORK_LOCATION = 'hr_work_location';

    /** Rekrutmen (HR-9a): lowongan & pelamar — tanpa nama/kontak pelamar dan isi catatan. */
    public const VACANCY_CREATED = 'hr.vacancy_created';

    public const VACANCY_UPDATED = 'hr.vacancy_updated';

    public const VACANCY_OPENED = 'hr.vacancy_opened';

    public const VACANCY_CLOSED = 'hr.vacancy_closed';

    public const VACANCY_DELETED = 'hr.vacancy_deleted';

    public const APPLICANT_ADDED = 'hr.applicant_added';

    public const APPLICANT_UPDATED = 'hr.applicant_updated';

    public const APPLICANT_STEP = 'hr.applicant_step';

    public const APPLICANT_FILE_DOWNLOADED = 'hr.applicant_file_downloaded';

    /** HR-9b: pelamar dijadikan pegawai; pelamar lama dihapus otomatis (lowongan, jumlah). */
    public const APPLICANT_HIRED = 'hr.applicant_hired';

    public const APPLICANTS_PURGED = 'hr.applicants_purged';

    public const SUBJECT_VACANCY = 'hr_vacancy';

    public const SUBJECT_APPLICANT = 'hr_applicant';

    /** Penilaian kinerja (HR-8b): periode, komponen per kelompok, penilaian — tanpa nilai & catatan. */
    public const PERFORMANCE_PERIOD_CREATED = 'hr.performance_period_created';

    public const PERFORMANCE_PERIOD_UPDATED = 'hr.performance_period_updated';

    public const PERFORMANCE_PERIOD_DELETED = 'hr.performance_period_deleted';

    /** Periode dikunci (jumlah dinilai) / dibuka kuncinya (dengan alasan, seperti buka kunci rekap gaji). */
    public const PERFORMANCE_PERIOD_LOCKED = 'hr.performance_period_locked';

    public const PERFORMANCE_PERIOD_UNLOCKED = 'hr.performance_period_unlocked';

    public const PERFORMANCE_COMPONENTS_UPDATED = 'hr.performance_components_updated';

    public const PERFORMANCE_ASSESSED = 'hr.performance_assessed';

    public const SUBJECT_PERFORMANCE_PERIOD = 'hr_performance_period';

    public const SUBJECT_PERFORMANCE_COMPONENTS = 'hr_performance_components';

    public const SUBJECT_PERFORMANCE_ASSESSMENT = 'hr_performance_assessment';

    /** Komponen gaji (HR-5a). Nominal tidak pernah dicatat di audit. */
    public const PAY_COMPONENT_CREATED = 'hr.pay_component_created';

    public const PAY_COMPONENT_UPDATED = 'hr.pay_component_updated';

    public const PAY_COMPONENT_DEACTIVATED = 'hr.pay_component_deactivated';

    public const PAY_COMPONENT_REACTIVATED = 'hr.pay_component_reactivated';

    public const EMPLOYEE_PAY_UPDATED = 'hr.employee_pay_updated';

    public const SUBJECT_PAY_COMPONENT = 'hr_pay_component';

    /** Tarif komponen Menurut jabatan disimpan/dihapus (HR-5a-2). */
    public const PAY_RATE_SET = 'hr.pay_rate_set';

    public const PAY_RATE_REMOVED = 'hr.pay_rate_removed';

    /** Rekap gaji bulanan (HR-5c-1): kunci, buka kunci, penyesuaian manual. Tanpa nominal. */
    public const PAYROLL_LOCKED = 'hr.payroll_locked';

    public const PAYROLL_UNLOCKED = 'hr.payroll_unlocked';

    public const PAYROLL_ADJUSTED = 'hr.payroll_adjusted';

    public const SUBJECT_PAYROLL_MONTH = 'hr_payroll_month';

    /** Daftar pegawai diunduh .xlsx (HR-6a): saringan, jumlah baris, jumlah baris berdata pribadi. */
    public const EMPLOYEES_EXPORTED = 'hr.employees_exported';

    public const SUBJECT_LEAVE_TYPE = 'hr_leave_type';

    public const SUBJECT_HOLIDAY = 'hr_holiday';

    /** Jenis subjek audit untuk data pegawai. */
    public const SUBJECT_EMPLOYEE = 'hr_employee';

    /**
     * @return array<string, string> aksi => label
     */
    public static function labels(): array
    {
        return [
            self::CATALOG_CREATED => 'Katalog SDM ditambahkan',
            self::CATALOG_RENAMED => 'Nama katalog SDM diubah',
            self::CATALOG_UPDATED => 'Katalog SDM diubah',
            self::CATALOG_DEACTIVATED => 'Katalog SDM dinonaktifkan',
            self::CATALOG_ACTIVATED => 'Katalog SDM diaktifkan kembali',
            self::EMPLOYEE_CREATED => 'Pegawai ditambahkan',
            self::EMPLOYEE_UPDATED => 'Data pegawai diubah',
            self::STATUS_CHANGED => 'Status kepegawaian diganti',
            self::PLACEMENT_ADDED => 'Penempatan ditambahkan',
            self::PLACEMENT_ENDED => 'Penempatan diakhiri',
            self::PLACEMENT_TRANSFERRED => 'Pegawai dimutasi',
            self::PLACEMENT_PRIMARY_CHANGED => 'Penempatan utama diganti',
            self::EMPLOYMENT_ENDED => 'Pegawai diberhentikan',
            self::EMPLOYEE_REHIRED => 'Pegawai diaktifkan kembali',
            self::EMPLOYEES_IMPORTED => 'Pegawai diimpor dari file',
            self::LEAVE_TYPE_CREATED => 'Jenis cuti ditambahkan',
            self::LEAVE_TYPE_UPDATED => 'Jenis cuti diubah',
            self::LEAVE_TYPE_DEACTIVATED => 'Jenis cuti dinonaktifkan',
            self::LEAVE_TYPE_REACTIVATED => 'Jenis cuti diaktifkan kembali',
            self::HOLIDAY_ADDED => 'Hari libur kalender kerja ditambahkan',
            self::HOLIDAY_UPDATED => 'Hari libur kalender kerja diubah',
            self::HOLIDAY_REMOVED => 'Hari libur kalender kerja dihapus',
            self::LEAVE_REQUESTED => 'Cuti diajukan',
            self::LEAVE_WITHDRAWN => 'Pengajuan cuti ditarik',
            self::LEAVE_APPROVED => 'Cuti disetujui',
            self::LEAVE_REJECTED => 'Cuti ditolak',
            self::LEAVE_RECORDED => 'Cuti dicatat atas nama pegawai',
            self::LEAVE_CANCELLED => 'Cuti dibatalkan',
            self::LEAVE_ADJUSTED => 'Jatah cuti disesuaikan',
            self::ATTENDANCE_CHECKED_IN => 'Pegawai absen masuk',
            self::ATTENDANCE_CHECKED_OUT => 'Pegawai absen pulang',
            self::ATTENDANCE_RECORDED => 'Presensi pegawai dicatat petugas',
            self::TRAINING_RECORDED => 'Riwayat pelatihan pegawai dicatat',
            self::TRAINING_SUBMITTED => 'Pegawai mengajukan riwayat pelatihan',
            self::TRAINING_VERIFIED => 'Riwayat pelatihan diterima',
            self::TRAINING_REJECTED => 'Riwayat pelatihan ditolak',
            self::TRAINING_CANCELLED => 'Riwayat pelatihan dibatalkan',
            self::TRAINING_WITHDRAWN => 'Pegawai menarik pengajuan pelatihan',
            self::WORK_LOCATION_SET => 'Titik lokasi absen lembaga diatur',
            self::WORK_LOCATION_REMOVED => 'Titik lokasi absen lembaga dihapus',
            self::VACANCY_CREATED => 'Lowongan dibuat',
            self::VACANCY_UPDATED => 'Lowongan diubah',
            self::VACANCY_OPENED => 'Lowongan dibuka',
            self::VACANCY_CLOSED => 'Lowongan ditutup',
            self::VACANCY_DELETED => 'Lowongan dihapus',
            self::APPLICANT_ADDED => 'Pelamar ditambahkan',
            self::APPLICANT_UPDATED => 'Data pelamar diubah',
            self::APPLICANT_STEP => 'Tahap seleksi pelamar dicatat',
            self::APPLICANT_FILE_DOWNLOADED => 'Berkas lamaran diunduh',
            self::APPLICANT_HIRED => 'Pelamar dijadikan pegawai',
            self::APPLICANTS_PURGED => 'Data pelamar lama dihapus otomatis',
            self::PERFORMANCE_PERIOD_CREATED => 'Periode penilaian kinerja dibuat',
            self::PERFORMANCE_PERIOD_UPDATED => 'Periode penilaian kinerja diubah',
            self::PERFORMANCE_PERIOD_DELETED => 'Periode penilaian kinerja dihapus',
            self::PERFORMANCE_PERIOD_LOCKED => 'Periode penilaian kinerja dikunci',
            self::PERFORMANCE_PERIOD_UNLOCKED => 'Kunci periode penilaian kinerja dibuka',
            self::PERFORMANCE_COMPONENTS_UPDATED => 'Komponen penilaian kinerja diubah',
            self::PERFORMANCE_ASSESSED => 'Kinerja pegawai dinilai',
            self::OFFBOARDING_STARTED => 'Checklist offboarding pegawai dibuat',
            self::OFFBOARDING_TASK_UPDATED => 'Item checklist offboarding diubah',
            self::DISCIPLINE_RECORDED => 'Catatan disiplin pegawai dicatat',
            self::DISCIPLINE_REVOKED => 'Catatan disiplin pegawai dicabut',
            self::DOCUMENT_ADDED => 'Dokumen pegawai ditambahkan',
            self::DOCUMENT_UPDATED => 'Isian dokumen pegawai diubah',
            self::DOCUMENT_VOIDED => 'Dokumen pegawai dibatalkan',
            self::DOCUMENT_DOWNLOADED => 'Dokumen pegawai diunduh',
            self::PAY_COMPONENT_CREATED => 'Komponen gaji ditambahkan',
            self::PAY_COMPONENT_UPDATED => 'Nama komponen gaji diubah',
            self::PAY_COMPONENT_DEACTIVATED => 'Komponen gaji dinonaktifkan',
            self::PAY_COMPONENT_REACTIVATED => 'Komponen gaji diaktifkan kembali',
            self::EMPLOYEE_PAY_UPDATED => 'Nominal komponen gaji pegawai diubah',
            self::PAY_RATE_SET => 'Tarif jabatan komponen gaji disimpan',
            self::PAY_RATE_REMOVED => 'Tarif jabatan komponen gaji dihapus',
            self::PAYROLL_LOCKED => 'Rekap gaji bulan dikunci',
            self::PAYROLL_UNLOCKED => 'Kunci rekap gaji dibuka',
            self::PAYROLL_ADJUSTED => 'Penyesuaian gaji ditambah/dihapus',
            self::EMPLOYEES_EXPORTED => 'Daftar pegawai diunduh',
        ];
    }
}
