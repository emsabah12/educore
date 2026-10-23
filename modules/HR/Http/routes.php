<?php

use Illuminate\Support\Facades\Route;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Http\Controllers\CatalogController;
use Modules\HR\Http\Controllers\EmployeeActionController;
use Modules\HR\Http\Controllers\EmployeeController;
use Modules\HR\Http\Controllers\EmployeeImportController;
use Modules\HR\Http\Controllers\LeaveAdminController;
use Modules\HR\Http\Controllers\LeaveApprovalController;
use Modules\HR\Http\Controllers\LeaveBalanceController;
use Modules\HR\Http\Controllers\LeaveSettingsController;
use Modules\HR\Http\Controllers\MyAttendanceController;
use Modules\HR\Http\Controllers\MyLeaveController;

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

    // Presensi saya — absen mandiri (PRD-001 §14.2, HR-4a). Tanpa permission: akun tertaut ke data pegawai.
    Route::get('presensi-saya', [MyAttendanceController::class, 'index'])->name('hr.my-attendance.index');
    Route::post('presensi-saya/masuk', [MyAttendanceController::class, 'checkIn'])->name('hr.my-attendance.check-in');
    Route::post('presensi-saya/pulang', [MyAttendanceController::class, 'checkOut'])->name('hr.my-attendance.check-out');

    // Persetujuan cuti (PRD-001 §13.4, HR-3c): tahap lembaga (hr.leave.approve) & tahap SDM (hr.leave.manage).
    Route::get('persetujuan-cuti', [LeaveApprovalController::class, 'index'])->name('hr.leave-approvals.index');
    Route::post('persetujuan-cuti/{leave}', [LeaveApprovalController::class, 'decide'])->whereUuid('leave')->name('hr.leave-approvals.decide');
    Route::post('pegawai/cuti/jam-kerja', [LeaveSettingsController::class, 'assignWorkHours'])->name('hr.work-hours.assign');
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

    // Pegawai (PRD-001 §6). `pegawai/tambah`, `pegawai/impor` & `pegawai/katalog` didaftarkan sebelum `pegawai/{employee}`.
    Route::get('pegawai', [EmployeeController::class, 'index'])->name('hr.employees.index');
    Route::get('pegawai/tambah', [EmployeeController::class, 'create'])->name('hr.employees.create');
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
    Route::post('pegawai/{employee}/status', [EmployeeActionController::class, 'changeStatus'])->whereUuid('employee')->name('hr.employees.status');
    Route::post('pegawai/{employee}/penempatan', [EmployeeActionController::class, 'addPlacement'])->whereUuid('employee')->name('hr.employees.placements.store');
    Route::post('pegawai/{employee}/penempatan/{placement}/akhiri', [EmployeeActionController::class, 'endPlacement'])->whereUuid(['employee', 'placement'])->name('hr.employees.placements.end');
    Route::post('pegawai/{employee}/penempatan/{placement}/mutasi', [EmployeeActionController::class, 'transfer'])->whereUuid(['employee', 'placement'])->name('hr.employees.placements.transfer');
    Route::post('pegawai/{employee}/penempatan/{placement}/utama', [EmployeeActionController::class, 'makePrimary'])->whereUuid(['employee', 'placement'])->name('hr.employees.placements.primary');
    Route::post('pegawai/{employee}/berhenti', [EmployeeActionController::class, 'end'])->whereUuid('employee')->name('hr.employees.end');
    Route::post('pegawai/{employee}/aktifkan', [EmployeeActionController::class, 'rehire'])->whereUuid('employee')->name('hr.employees.rehire');
});
