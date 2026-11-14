<?php

use Illuminate\Support\Facades\Route;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Http\Controllers\A05Controller;
use Modules\HR\Http\Controllers\AttendanceSheetController;
use Modules\HR\Http\Controllers\CatalogController;
use Modules\HR\Http\Controllers\DisciplineController;
use Modules\HR\Http\Controllers\EmployeeActionController;
use Modules\HR\Http\Controllers\EmployeeController;
use Modules\HR\Http\Controllers\EmployeeDocumentController;
use Modules\HR\Http\Controllers\EmployeeImportController;
use Modules\HR\Http\Controllers\EmployeeReportController;
use Modules\HR\Http\Controllers\ExpiringDocumentController;
use Modules\HR\Http\Controllers\LeaveAdminController;
use Modules\HR\Http\Controllers\LeaveApprovalController;
use Modules\HR\Http\Controllers\LeaveBalanceController;
use Modules\HR\Http\Controllers\LeaveSettingsController;
use Modules\HR\Http\Controllers\MyAttendanceController;
use Modules\HR\Http\Controllers\MyDocumentController;
use Modules\HR\Http\Controllers\MyLeaveController;
use Modules\HR\Http\Controllers\MyPerformanceController;
use Modules\HR\Http\Controllers\MyTrainingController;
use Modules\HR\Http\Controllers\OffboardingController;
use Modules\HR\Http\Controllers\PayrollEmployeeController;
use Modules\HR\Http\Controllers\PayrollRecapController;
use Modules\HR\Http\Controllers\PayrollSettingsController;
use Modules\HR\Http\Controllers\PayslipController;
use Modules\HR\Http\Controllers\PerformanceController;
use Modules\HR\Http\Controllers\PtkProfileController;
use Modules\HR\Http\Controllers\PtkRecapController;
use Modules\HR\Http\Controllers\RecruitmentController;
use Modules\HR\Http\Controllers\TrainingController;

/*
 * Route web modul HR (PRD-001 §6). Dimuat oleh ModuleServiceProvider dengan middleware group "web".
 * Semua halaman HR bekerja di dalam yayasan, jadi wajib memakai `work.context`.
 */
Route::middleware(['auth', 'work.context'])->group(function () {
    $kinds = array_map(fn (CatalogKind $kind): string => $kind->value, CatalogKind::cases());

    // Katalog SDM: ?jenis=jenis-pegawai|status-kepegawaian|jabatan
    Route::get('pegawai/katalog', [CatalogController::class, 'index'])->name('hr.catalog.index');
    Route::post('pegawai/katalog/{kind}', [CatalogController::class, 'store'])->whereIn('kind', $kinds)->name('hr.catalog.store');
    Route::patch('pegawai/katalog/{kind}/{item}', [CatalogController::class, 'update'])->whereIn('kind', $kinds)->name('hr.catalog.update');
    Route::post('pegawai/katalog/{kind}/{item}/nonaktifkan', [CatalogController::class, 'deactivate'])->whereIn('kind', $kinds)->name('hr.catalog.deactivate');
    Route::post('pegawai/katalog/{kind}/{item}/aktifkan', [CatalogController::class, 'activate'])->whereIn('kind', $kinds)->name('hr.catalog.activate');

    // Pengaturan cuti (PRD-001 §13.2, HR-3a): jenis cuti, libur mingguan, hari libur. Admin SDM / Admin Yayasan.
    Route::get('pegawai/cuti/pengaturan', [LeaveSettingsController::class, 'index'])->name('hr.leave-settings.index');
    Route::post('pegawai/cuti/jenis', [LeaveSettingsController::class, 'storeType'])->name('hr.leave-types.store');
    Route::patch('pegawai/cuti/jenis/{type}', [LeaveSettingsController::class, 'updateType'])->whereUuid('type')->name('hr.leave-types.update');
    Route::post('pegawai/cuti/jenis/{type}/nonaktifkan', [LeaveSettingsController::class, 'deactivateType'])->whereUuid('type')->name('hr.leave-types.deactivate');
    Route::post('pegawai/cuti/jenis/{type}/aktifkan', [LeaveSettingsController::class, 'activateType'])->whereUuid('type')->name('hr.leave-types.activate');
    Route::post('pegawai/cuti/libur-mingguan', [LeaveSettingsController::class, 'assignWeeklyOff'])->name('hr.weekly-off.assign');
    Route::post('pegawai/cuti/hari-libur', [LeaveSettingsController::class, 'storeHoliday'])->name('hr.holidays.store');
    Route::patch('pegawai/cuti/hari-libur/{holiday}', [LeaveSettingsController::class, 'updateHoliday'])->whereUuid('holiday')->name('hr.holidays.update');
    Route::delete('pegawai/cuti/hari-libur/{holiday}', [LeaveSettingsController::class, 'destroyHoliday'])->whereUuid('holiday')->name('hr.holidays.destroy');

    // Cuti saya — layanan mandiri pegawai (PRD-001 §13.3, HR-3b). Tanpa permission: akun tertaut ke data pegawai.
    Route::get('cuti-saya', [MyLeaveController::class, 'index'])->name('hr.my-leave.index');
    Route::get('cuti-saya/hitung', [MyLeaveController::class, 'preview'])->name('hr.my-leave.preview');
    Route::post('cuti-saya', [MyLeaveController::class, 'store'])->name('hr.my-leave.store');
    Route::post('cuti-saya/{leave}/tarik', [MyLeaveController::class, 'withdraw'])->whereUuid('leave')->name('hr.my-leave.withdraw');

    // Gaji saya — slip gaji milik sendiri dari bulan terkunci (PRD-001 §16.3, HR-5c-2).
    Route::get('gaji-saya', [PayslipController::class, 'myIndex'])->name('hr.my-pay.index');
    Route::get('gaji-saya/{month}', [PayslipController::class, 'mySlip'])->where('month', '\d{4}-\d{2}')->name('hr.my-pay.payslip');

    // Presensi saya — absen mandiri (PRD-001 §14.2, HR-4a). Tanpa permission: akun tertaut ke data pegawai.
    // Lembar presensi pegawai oleh petugas (PRD-001 §14.3, HR-4b).
    Route::get('pegawai/presensi', [AttendanceSheetController::class, 'index'])->name('hr.attendance.index');
    Route::post('pegawai/presensi', [AttendanceSheetController::class, 'store'])->name('hr.attendance.store');
    Route::get('pegawai/presensi/rekap', [AttendanceSheetController::class, 'recap'])->name('hr.attendance.recap');
    Route::get('pegawai/presensi/rekap/unduh', [AttendanceSheetController::class, 'recapExport'])->name('hr.attendance.recap.export');

    Route::get('presensi-saya', [MyAttendanceController::class, 'index'])->name('hr.my-attendance.index');
    Route::post('presensi-saya/masuk', [MyAttendanceController::class, 'checkIn'])->name('hr.my-attendance.check-in');
    Route::post('presensi-saya/pulang', [MyAttendanceController::class, 'checkOut'])->name('hr.my-attendance.check-out');

    // Persetujuan cuti (PRD-001 §13.4, HR-3c): tahap lembaga (hr.leave.approve) & tahap SDM (hr.leave.manage).
    Route::get('persetujuan-cuti', [LeaveApprovalController::class, 'index'])->name('hr.leave-approvals.index');
    Route::post('persetujuan-cuti/{leave}', [LeaveApprovalController::class, 'decide'])->whereUuid('leave')->name('hr.leave-approvals.decide');
    Route::post('pegawai/cuti/jam-kerja', [LeaveSettingsController::class, 'assignWorkHours'])->name('hr.work-hours.assign');
    Route::post('pegawai/cuti/lokasi-absen', [LeaveSettingsController::class, 'assignAttendanceLocation'])->name('hr.attendance-location.assign');
    Route::put('pegawai/cuti/titik-lokasi/{organization}', [LeaveSettingsController::class, 'setWorkLocation'])->whereUuid('organization')->name('hr.work-locations.set');
    Route::delete('pegawai/cuti/titik-lokasi/{organization}', [LeaveSettingsController::class, 'removeWorkLocation'])->whereUuid('organization')->name('hr.work-locations.remove');
    Route::post('pegawai/cuti/alur-persetujuan', [LeaveSettingsController::class, 'assignApproval'])->name('hr.leave-approval-flow.assign');

    // Kelola cuti — Admin SDM (PRD-001 §13.5, HR-3d): daftar, detail, catat atas nama, batalkan.
    Route::get('pegawai/cuti', [LeaveAdminController::class, 'index'])->name('hr.leaves.index');
    Route::get('pegawai/cuti/catat', [LeaveAdminController::class, 'create'])->name('hr.leaves.create');
    Route::get('pegawai/cuti/hitung', [LeaveAdminController::class, 'preview'])->name('hr.leaves.preview');
    Route::post('pegawai/cuti', [LeaveAdminController::class, 'store'])->name('hr.leaves.store');
    // Saldo, penyesuaian jatah & rekap .xlsx (HR-3d-b).
    Route::get('pegawai/cuti/saldo', [LeaveBalanceController::class, 'index'])->name('hr.leave-balances.index');
    Route::post('pegawai/cuti/saldo/{employee}', [LeaveBalanceController::class, 'adjust'])->whereUuid('employee')->name('hr.leave-balances.adjust');
    Route::get('pegawai/cuti/rekap', [LeaveBalanceController::class, 'export'])->name('hr.leave-balances.export');
    Route::get('pegawai/cuti/{leave}', [LeaveAdminController::class, 'show'])->whereUuid('leave')->name('hr.leaves.show');
    Route::post('pegawai/cuti/{leave}/batalkan', [LeaveAdminController::class, 'cancel'])->whereUuid('leave')->name('hr.leaves.cancel');

    // Dokumen saya (layanan mandiri) & Dokumen akan habis (Admin SDM) — PRD-001 §15.2, HR-2b.
    Route::get('dokumen-saya', [MyDocumentController::class, 'index'])->name('hr.my-documents.index');
    Route::get('dokumen-saya/{document}/unduh', [MyDocumentController::class, 'download'])->whereUuid('document')->name('hr.my-documents.download');
    Route::get('pegawai/dokumen/akan-habis', [ExpiringDocumentController::class, 'index'])->name('hr.documents.expiring');
    Route::get('dokumen-saya/disiplin/{record}/unduh', [MyDocumentController::class, 'downloadDiscipline'])->whereUuid('record')->name('hr.my-documents.discipline.download');

    // Catatan disiplin (PRD-001 §18.1, HR-7a).
    Route::get('pegawai/disiplin', [DisciplineController::class, 'index'])->name('hr.discipline.index');

    // Offboarding pegawai keluar (PRD-001 §18.2, HR-7b).
    Route::get('pegawai/keluar', [OffboardingController::class, 'index'])->name('hr.offboarding.index');

    // Riwayat pelatihan (PRD-001 §19.1, HR-8a).
    Route::get('pegawai/pelatihan', [TrainingController::class, 'index'])->name('hr.trainings.index');
    Route::get('pegawai/pelatihan/unduh', [TrainingController::class, 'export'])->middleware('throttle:10,1')->name('hr.trainings.export');
    Route::get('pelatihan-saya', [MyTrainingController::class, 'index'])->name('hr.my-trainings.index');
    Route::post('pelatihan-saya', [MyTrainingController::class, 'store'])->name('hr.my-trainings.store');
    Route::post('pelatihan-saya/{training}/tarik', [MyTrainingController::class, 'withdraw'])->whereUuid('training')->name('hr.my-trainings.withdraw');
    Route::get('pelatihan-saya/{training}/unduh', [MyTrainingController::class, 'download'])->whereUuid('training')->name('hr.my-trainings.download');

    // Rekrutmen (PRD-001 §20, HR-9a).
    Route::get('pegawai/rekrutmen', [RecruitmentController::class, 'index'])->name('hr.recruitment.index');
    Route::post('pegawai/rekrutmen', [RecruitmentController::class, 'storeVacancy'])->name('hr.recruitment.store');
    Route::get('pegawai/rekrutmen/{vacancy}', [RecruitmentController::class, 'show'])->whereUuid('vacancy')->name('hr.recruitment.show');
    Route::patch('pegawai/rekrutmen/{vacancy}', [RecruitmentController::class, 'updateVacancy'])->whereUuid('vacancy')->name('hr.recruitment.update');
    Route::delete('pegawai/rekrutmen/{vacancy}', [RecruitmentController::class, 'destroyVacancy'])->whereUuid('vacancy')->name('hr.recruitment.destroy');
    Route::post('pegawai/rekrutmen/{vacancy}/buka', [RecruitmentController::class, 'openVacancy'])->whereUuid('vacancy')->name('hr.recruitment.open');
    Route::post('pegawai/rekrutmen/{vacancy}/tutup', [RecruitmentController::class, 'closeVacancy'])->whereUuid('vacancy')->name('hr.recruitment.close');
    Route::post('pegawai/rekrutmen/{vacancy}/pelamar', [RecruitmentController::class, 'storeApplicant'])->whereUuid('vacancy')->name('hr.applicants.store');
    Route::post('pegawai/rekrutmen/{vacancy}/pelamar/{applicant}', [RecruitmentController::class, 'updateApplicant'])->whereUuid(['vacancy', 'applicant'])->name('hr.applicants.update');
    Route::post('pegawai/rekrutmen/{vacancy}/pelamar/{applicant}/tahap', [RecruitmentController::class, 'step'])->whereUuid(['vacancy', 'applicant'])->name('hr.applicants.step');
    Route::get('pegawai/rekrutmen/{vacancy}/rekap', [RecruitmentController::class, 'export'])->whereUuid('vacancy')->middleware('throttle:10,1')->name('hr.recruitment.export');
    Route::get('pegawai/rekrutmen/{vacancy}/pelamar/{applicant}/unduh', [RecruitmentController::class, 'download'])->whereUuid(['vacancy', 'applicant'])->name('hr.applicants.download');

    // Penilaian kinerja ringkas (PRD-001 §19.2, HR-8b).
    Route::get('pegawai/kinerja', [PerformanceController::class, 'index'])->name('hr.performance.index');
    Route::post('pegawai/kinerja/periode', [PerformanceController::class, 'storePeriod'])->name('hr.performance.periods.store');
    Route::patch('pegawai/kinerja/periode/{period}', [PerformanceController::class, 'updatePeriod'])->whereUuid('period')->name('hr.performance.periods.update');
    Route::delete('pegawai/kinerja/periode/{period}', [PerformanceController::class, 'destroyPeriod'])->whereUuid('period')->name('hr.performance.periods.destroy');
    Route::post('pegawai/kinerja/periode/{period}/kunci', [PerformanceController::class, 'lockPeriod'])->whereUuid('period')->name('hr.performance.periods.lock');
    Route::post('pegawai/kinerja/periode/{period}/buka-kunci', [PerformanceController::class, 'unlockPeriod'])->whereUuid('period')->name('hr.performance.periods.unlock');
    Route::get('pegawai/kinerja/rekap/unduh', [PerformanceController::class, 'export'])->middleware('throttle:10,1')->name('hr.performance.export');
    Route::get('kinerja-saya', [MyPerformanceController::class, 'index'])->name('hr.my-performance.index');
    Route::post('pegawai/kinerja/komponen/{group}', [PerformanceController::class, 'saveComponents'])->whereIn('group', ['guru', 'staf'])->name('hr.performance.components.save');
    Route::post('pegawai/kinerja/periode/{period}/pegawai/{employee}', [PerformanceController::class, 'assess'])->whereUuid(['period', 'employee'])->name('hr.performance.assess');

    // Pengaturan gaji (PRD-001 §16, HR-5a): komponen gaji. Admin SDM / Admin Yayasan.
    Route::get('pegawai/gaji/pengaturan', [PayrollSettingsController::class, 'index'])->name('hr.payroll-settings.index');
    Route::post('pegawai/gaji/komponen', [PayrollSettingsController::class, 'store'])->name('hr.pay-components.store');
    Route::patch('pegawai/gaji/komponen/{component}', [PayrollSettingsController::class, 'update'])->whereUuid('component')->name('hr.pay-components.update');
    Route::post('pegawai/gaji/komponen/{component}/nonaktifkan', [PayrollSettingsController::class, 'deactivate'])->whereUuid('component')->name('hr.pay-components.deactivate');
    Route::post('pegawai/gaji/komponen/{component}/aktifkan', [PayrollSettingsController::class, 'activate'])->whereUuid('component')->name('hr.pay-components.activate');
    // Tarif komponen Menurut jabatan (HR-5a-2).
    Route::post('pegawai/gaji/komponen/{component}/tarif', [PayrollSettingsController::class, 'setRate'])->whereUuid('component')->name('hr.pay-rates.set');
    Route::delete('pegawai/gaji/komponen/{component}/tarif/{rate}', [PayrollSettingsController::class, 'removeRate'])->whereUuid(['component', 'rate'])->name('hr.pay-rates.remove');
    // Honor mengajar & potongan presensi per jenjang/lembaga (HR-5b).
    Route::post('pegawai/gaji/honor-jp', [PayrollSettingsController::class, 'setTeachingPay'])->name('hr.payroll.teaching-pay');
    Route::post('pegawai/gaji/potongan-presensi', [PayrollSettingsController::class, 'setDeduction'])->name('hr.payroll.deduction');
    // Rekap gaji bulanan: penyesuaian, kunci/buka kunci, unduh .xlsx (HR-5c-1).
    Route::get('pegawai/gaji/rekap', [PayrollRecapController::class, 'index'])->name('hr.payroll-recap.index');
    Route::get('pegawai/gaji/rekap/unduh', [PayrollRecapController::class, 'export'])->name('hr.payroll-recap.export');
    Route::post('pegawai/gaji/rekap/{month}/kunci', [PayrollRecapController::class, 'lock'])->where('month', '\d{4}-\d{2}')->name('hr.payroll-recap.lock');
    Route::post('pegawai/gaji/rekap/{month}/buka-kunci', [PayrollRecapController::class, 'unlock'])->where('month', '\d{4}-\d{2}')->name('hr.payroll-recap.unlock');
    // Slip gaji dari bulan terkunci (HR-5c-2): cetak semua / per lembaga / per pegawai.
    Route::get('pegawai/gaji/rekap/{month}/slip', [PayslipController::class, 'print'])->where('month', '\d{4}-\d{2}')->name('hr.payroll-recap.payslips');
    Route::post('pegawai/gaji/rekap/{month}/penyesuaian', [PayrollRecapController::class, 'storeAdjustment'])->where('month', '\d{4}-\d{2}')->name('hr.payroll-recap.adjustments.store');
    Route::delete('pegawai/gaji/rekap/{month}/penyesuaian/{adjustment}', [PayrollRecapController::class, 'destroyAdjustment'])->where('month', '\d{4}-\d{2}')->whereUuid('adjustment')->name('hr.payroll-recap.adjustments.destroy');
    // Menu Gaji pegawai: daftar & setup per pegawai (HR-5a-2), terpisah dari detail pegawai.
    Route::get('pegawai/gaji', [PayrollEmployeeController::class, 'index'])->name('hr.payroll-employees.index');
    Route::get('pegawai/gaji/{employee}', [PayrollEmployeeController::class, 'show'])->whereUuid('employee')->name('hr.payroll-employees.show');
    Route::patch('pegawai/gaji/{employee}', [PayrollEmployeeController::class, 'update'])->whereUuid('employee')->name('hr.payroll-employees.update');

    // Pegawai (PRD-001 §6). `pegawai/tambah`, `pegawai/impor` & `pegawai/katalog` didaftarkan sebelum `pegawai/{employee}`.
    Route::get('pegawai', [EmployeeController::class, 'index'])->name('hr.employees.index');
    Route::get('pegawai/tambah', [EmployeeController::class, 'create'])->name('hr.employees.create');
    // Laporan pegawai & ekspor daftar (PRD-001 §17, HR-6a). Cakupan sama dengan daftar Pegawai.
    Route::get('pegawai/unduh', [EmployeeReportController::class, 'exportList'])->name('hr.employees.export');
    Route::get('pegawai/laporan', [EmployeeReportController::class, 'index'])->name('hr.employees.report');
    Route::get('pegawai/laporan/unduh', [EmployeeReportController::class, 'exportReport'])->name('hr.employees.report.export');
    // Rekap PTK Simpatika (PRD-001 §21.2, HR-10b).
    Route::get('pegawai/rekap-ptk', [PtkRecapController::class, 'index'])->name('hr.ptk-recap.index');
    Route::get('pegawai/rekap-ptk/a05', [A05Controller::class, 'print'])->name('hr.a05.print');
    Route::get('pegawai/rekap-ptk/unduh', [PtkRecapController::class, 'export'])->middleware('throttle:10,1')->name('hr.ptk-recap.export');
    Route::post('pegawai', [EmployeeController::class, 'store'])->name('hr.employees.store');
    // Impor pegawai dari Excel/CSV (HR-1e, PRD-001 §6.2). Hanya Admin SDM / Admin Yayasan.
    Route::get('pegawai/impor', [EmployeeImportController::class, 'index'])->name('hr.employees.import');
    Route::get('pegawai/impor/template', [EmployeeImportController::class, 'template'])->name('hr.employees.import.template');
    Route::post('pegawai/impor', [EmployeeImportController::class, 'upload'])->name('hr.employees.import.upload');
    Route::post('pegawai/impor/simpan', [EmployeeImportController::class, 'save'])->name('hr.employees.import.save');
    Route::post('pegawai/impor/batal', [EmployeeImportController::class, 'cancel'])->name('hr.employees.import.cancel');

    Route::get('pegawai/{employee}', [EmployeeController::class, 'show'])->whereUuid('employee')->name('hr.employees.show');

    // Aksi di detail pegawai (HR-1d). Hanya Admin SDM / Admin Yayasan.
    Route::patch('pegawai/{employee}', [EmployeeActionController::class, 'updateProfile'])->whereUuid('employee')->name('hr.employees.update');
    Route::patch('pegawai/{employee}/ptk', [PtkProfileController::class, 'update'])->whereUuid('employee')->name('hr.employees.ptk.update');
    Route::post('pegawai/{employee}/status', [EmployeeActionController::class, 'changeStatus'])->whereUuid('employee')->name('hr.employees.status');
    Route::post('pegawai/{employee}/penempatan', [EmployeeActionController::class, 'addPlacement'])->whereUuid('employee')->name('hr.employees.placements.store');
    Route::post('pegawai/{employee}/penempatan/{placement}/akhiri', [EmployeeActionController::class, 'endPlacement'])->whereUuid(['employee', 'placement'])->name('hr.employees.placements.end');
    Route::post('pegawai/{employee}/penempatan/{placement}/mutasi', [EmployeeActionController::class, 'transfer'])->whereUuid(['employee', 'placement'])->name('hr.employees.placements.transfer');
    Route::post('pegawai/{employee}/penempatan/{placement}/utama', [EmployeeActionController::class, 'makePrimary'])->whereUuid(['employee', 'placement'])->name('hr.employees.placements.primary');
    Route::post('pegawai/{employee}/berhenti', [EmployeeActionController::class, 'end'])->whereUuid('employee')->name('hr.employees.end');
    Route::post('pegawai/{employee}/aktifkan', [EmployeeActionController::class, 'rehire'])->whereUuid('employee')->name('hr.employees.rehire');

    // Dokumen & SK pegawai (PRD-001 §15, HR-2a). Kelola: Admin SDM / Admin Yayasan; unduh: lihat data pribadi.
    Route::post('pegawai/{employee}/dokumen', [EmployeeDocumentController::class, 'store'])->whereUuid('employee')->name('hr.employees.documents.store');
    Route::patch('pegawai/{employee}/dokumen/{document}', [EmployeeDocumentController::class, 'update'])->whereUuid(['employee', 'document'])->name('hr.employees.documents.update');
    Route::post('pegawai/{employee}/dokumen/{document}/batalkan', [EmployeeDocumentController::class, 'void'])->whereUuid(['employee', 'document'])->name('hr.employees.documents.void');
    Route::get('pegawai/{employee}/dokumen/{document}/unduh', [EmployeeDocumentController::class, 'download'])->whereUuid(['employee', 'document'])->name('hr.employees.documents.download');
    Route::post('pegawai/{employee}/disiplin', [DisciplineController::class, 'store'])->whereUuid('employee')->name('hr.employees.discipline.store');
    Route::post('pegawai/{employee}/disiplin/{record}/cabut', [DisciplineController::class, 'revoke'])->whereUuid(['employee', 'record'])->name('hr.employees.discipline.revoke');
    Route::post('pegawai/{employee}/pelatihan', [TrainingController::class, 'store'])->whereUuid('employee')->name('hr.employees.trainings.store');
    Route::post('pegawai/{employee}/pelatihan/{training}/terima', [TrainingController::class, 'verify'])->whereUuid(['employee', 'training'])->name('hr.employees.trainings.verify');
    Route::post('pegawai/{employee}/pelatihan/{training}/tolak', [TrainingController::class, 'reject'])->whereUuid(['employee', 'training'])->name('hr.employees.trainings.reject');
    Route::post('pegawai/{employee}/pelatihan/{training}/batalkan', [TrainingController::class, 'cancel'])->whereUuid(['employee', 'training'])->name('hr.employees.trainings.cancel');
    Route::get('pegawai/{employee}/pelatihan/{training}/unduh', [TrainingController::class, 'download'])->whereUuid(['employee', 'training'])->name('hr.employees.trainings.download');
    Route::post('pegawai/{employee}/offboarding', [OffboardingController::class, 'start'])->whereUuid('employee')->name('hr.employees.offboarding.start');
    Route::post('pegawai/{employee}/offboarding/{task}', [OffboardingController::class, 'updateTask'])->whereUuid(['employee', 'task'])->name('hr.employees.offboarding.task');
    Route::get('pegawai/{employee}/disiplin/{record}/unduh', [DisciplineController::class, 'download'])->whereUuid(['employee', 'record'])->name('hr.employees.discipline.download');
});
