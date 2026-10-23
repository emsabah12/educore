<?php

use Illuminate\Support\Facades\Route;
use Modules\Academic\Http\Controllers\AttendanceController;
use Modules\Academic\Http\Controllers\CalendarController;
use Modules\Academic\Http\Controllers\ClassController;
use Modules\Academic\Http\Controllers\ClassMemberController;
use Modules\Academic\Http\Controllers\ClassSubjectController;
use Modules\Academic\Http\Controllers\ClassYearEndController;
use Modules\Academic\Http\Controllers\DutyRosterController;
use Modules\Academic\Http\Controllers\GradeController;
use Modules\Academic\Http\Controllers\GradeLedgerController;
use Modules\Academic\Http\Controllers\MyClassesController;
use Modules\Academic\Http\Controllers\ReportCardController;
use Modules\Academic\Http\Controllers\ScheduleController;
use Modules\Academic\Http\Controllers\StudentActionController;
use Modules\Academic\Http\Controllers\StudentController;
use Modules\Academic\Http\Controllers\StudentGuardianController;
use Modules\Academic\Http\Controllers\StudentImportController;
use Modules\Academic\Http\Controllers\SubjectController;
use Modules\Academic\Http\Controllers\TeachingController;

/*
 * Route web modul Academic (PRD-002 §6). Dimuat oleh ModuleServiceProvider dengan middleware group "web".
 * Semua halaman Academic bekerja di dalam yayasan, jadi wajib memakai `work.context`.
 */
Route::middleware(['auth', 'work.context'])->group(function () {
    // Kalender akademik (AC-1a, PRD-002 §4.4). Hanya di tingkat Yayasan.
    Route::get('akademik/kalender', [CalendarController::class, 'index'])->name('academic.calendar.index');
    Route::post('akademik/kalender', [CalendarController::class, 'storeCalendar'])->name('academic.calendar.store');
    // `pemakaian` didaftarkan sebelum `{calendar}`.
    Route::post('akademik/kalender/pemakaian', [CalendarController::class, 'assign'])->name('academic.calendar.assign');
    Route::post('akademik/kalender/mode-presensi', [CalendarController::class, 'assignAttendanceMode'])->name('academic.attendance-mode.assign');
    Route::post('akademik/kalender/aturan-penilaian', [CalendarController::class, 'assignGrading'])->name('academic.grading.assign');
    Route::patch('akademik/kalender/{calendar}', [CalendarController::class, 'renameCalendar'])->whereUuid('calendar')->name('academic.calendar.update');
    Route::post('akademik/kalender/{calendar}/tahun-ajaran', [CalendarController::class, 'storeYear'])->whereUuid('calendar')->name('academic.years.store');
    Route::patch('akademik/tahun-ajaran/{year}', [CalendarController::class, 'updateYear'])->whereUuid('year')->name('academic.years.update');
    Route::post('akademik/tahun-ajaran/{year}/berjalan', [CalendarController::class, 'setCurrent'])->whereUuid('year')->name('academic.years.current');
    // Hari libur (PRD-002 §18 P4): sama dengan kalender, hanya Admin Yayasan.
    Route::post('akademik/kalender/{calendar}/libur-mingguan', [CalendarController::class, 'weeklyOffDays'])->whereUuid('calendar')->name('academic.calendar.weekly-off');
    Route::post('akademik/tahun-ajaran/{year}/hari-libur', [CalendarController::class, 'storeHoliday'])->whereUuid('year')->name('academic.holidays.store');
    Route::patch('akademik/hari-libur/{holiday}', [CalendarController::class, 'updateHoliday'])->whereUuid('holiday')->name('academic.holidays.update');
    Route::delete('akademik/hari-libur/{holiday}', [CalendarController::class, 'destroyHoliday'])->whereUuid('holiday')->name('academic.holidays.destroy');

    // Siswa (PRD-002 §6). Daftar & detail (AC-1c-a): pemilik izin lihat siswa, disaring per pohon lembaga.
    // Tambah (AC-1b): Operator Akademik di lembaganya, Admin Yayasan di semua lembaga.
    // `siswa/tambah` didaftarkan sebelum `siswa/{student}`.
    Route::get('siswa', [StudentController::class, 'index'])->name('academic.students.index');
    Route::get('siswa/tambah', [StudentController::class, 'create'])->name('academic.students.create');
    // Impor siswa (AC-1e). Satu file = satu lembaga; simpan semua atau tidak sama sekali.
    Route::get('siswa/impor', [StudentImportController::class, 'index'])->name('academic.students.import');
    Route::get('siswa/impor/template', [StudentImportController::class, 'template'])->name('academic.students.import.template');
    Route::post('siswa/impor', [StudentImportController::class, 'upload'])->name('academic.students.import.upload');
    Route::post('siswa/impor/simpan', [StudentImportController::class, 'save'])->name('academic.students.import.save');
    Route::post('siswa/impor/batal', [StudentImportController::class, 'cancel'])->name('academic.students.import.cancel');
    Route::post('siswa', [StudentController::class, 'store'])->name('academic.students.store');
    Route::get('siswa/{student}', [StudentController::class, 'show'])->whereUuid('student')->name('academic.students.show');

    // Aksi di detail siswa (AC-1c-b1). Hak akses dicek per aksi di controller (OD-AC-09, §5.3).
    Route::patch('siswa/{student}', [StudentActionController::class, 'updateProfile'])->whereUuid('student')->name('academic.students.update');
    Route::post('siswa/{student}/pendaftaran', [StudentActionController::class, 'enroll'])->whereUuid('student')->name('academic.enrollments.store');
    Route::patch('siswa/{student}/pendaftaran/{enrollment}', [StudentActionController::class, 'updateEnrollment'])
        ->whereUuid(['student', 'enrollment'])->name('academic.enrollments.update');
    Route::post('siswa/{student}/pendaftaran/{enrollment}/tutup', [StudentActionController::class, 'closeEnrollment'])
        ->whereUuid(['student', 'enrollment'])->name('academic.enrollments.close');
    // Buka kembali pendaftaran (§18 P2): Admin Yayasan saja.
    Route::post('siswa/{student}/pendaftaran/{enrollment}/buka-kembali', [StudentActionController::class, 'reopenEnrollment'])
        ->whereUuid(['student', 'enrollment'])->name('academic.enrollments.reopen');

    // Orang tua/wali di detail siswa (AC-1c-b2). Pencarian HP dibatasi 30 kali/menit agar tidak dipakai menebak nomor.
    Route::get('siswa/{student}/orang-tua/cari', [StudentGuardianController::class, 'search'])
        ->whereUuid('student')->middleware('throttle:30,1')->name('academic.guardians.search');
    Route::post('siswa/{student}/orang-tua', [StudentGuardianController::class, 'store'])->whereUuid('student')->name('academic.guardians.store');
    Route::patch('siswa/{student}/orang-tua/{guardian}', [StudentGuardianController::class, 'update'])
        ->whereUuid(['student', 'guardian'])->name('academic.guardians.update');
    Route::delete('siswa/{student}/orang-tua/{guardian}', [StudentGuardianController::class, 'destroy'])
        ->whereUuid(['student', 'guardian'])->name('academic.guardians.destroy');
    Route::post('siswa/{student}/orang-tua/{guardian}/kontak-utama', [StudentGuardianController::class, 'makePrimary'])
        ->whereUuid(['student', 'guardian'])->name('academic.guardians.primary');

    // Mata pelajaran (AC-3a, PRD-002 §16). Lihat: izin lihat siswa; kelola: academic.classes.manage di lembaga mapel.
    Route::get('akademik/mapel', [SubjectController::class, 'index'])->name('academic.subjects.index');
    Route::post('akademik/mapel', [SubjectController::class, 'store'])->name('academic.subjects.store');
    Route::patch('akademik/mapel/{subject}', [SubjectController::class, 'update'])->whereUuid('subject')->name('academic.subjects.update');
    Route::post('akademik/mapel/{subject}/status', [SubjectController::class, 'status'])->whereUuid('subject')->name('academic.subjects.status');
    Route::delete('akademik/mapel/{subject}', [SubjectController::class, 'destroy'])->whereUuid('subject')->name('academic.subjects.destroy');

    // Kelas/rombel (AC-1d-a, PRD-002 §6). Lihat: izin lihat siswa; kelola: academic.classes.manage di lembaga kelas.
    Route::get('kelas', [ClassController::class, 'index'])->name('academic.classes.index');
    Route::post('kelas', [ClassController::class, 'store'])->name('academic.classes.store');
    Route::patch('kelas/{class}', [ClassController::class, 'update'])->whereUuid('class')->name('academic.classes.update');
    Route::post('kelas/{class}/status', [ClassController::class, 'status'])->whereUuid('class')->name('academic.classes.status');
    Route::delete('kelas/{class}', [ClassController::class, 'destroy'])->whereUuid('class')->name('academic.classes.destroy');

    // Anggota kelas (AC-1d-b). Lihat: izin lihat siswa; kelola: academic.classes.manage di lembaga kelas.
    Route::get('kelas/{class}', [ClassMemberController::class, 'show'])->whereUuid('class')->name('academic.classes.show');
    // Unduh daftar siswa kelas (.xlsx, §18 P1).
    Route::get('kelas/{class}/unduh', [ClassMemberController::class, 'export'])->whereUuid('class')->name('academic.classes.export');
    Route::post('kelas/{class}/anggota', [ClassMemberController::class, 'add'])->whereUuid('class')->name('academic.class-members.store');
    Route::post('kelas/anggota/{member}/pindah', [ClassMemberController::class, 'move'])->whereUuid('member')->name('academic.class-members.move');
    Route::post('kelas/anggota/{member}/keluar', [ClassMemberController::class, 'remove'])->whereUuid('member')->name('academic.class-members.remove');

    // Mapel kelas & guru pengampu (AC-3b). Kelola: academic.classes.manage di lembaga kelas.
    Route::post('kelas/{class}/mapel', [ClassSubjectController::class, 'store'])->whereUuid('class')->name('academic.class-subjects.store');
    Route::post('kelas/{class}/mapel/salin', [ClassSubjectController::class, 'copy'])->whereUuid('class')->name('academic.class-subjects.copy');
    Route::patch('kelas/mapel/{classSubject}', [ClassSubjectController::class, 'update'])->whereUuid('classSubject')->name('academic.class-subjects.update');
    Route::delete('kelas/mapel/{classSubject}', [ClassSubjectController::class, 'destroy'])->whereUuid('classSubject')->name('academic.class-subjects.destroy');

    // Jadwal pelajaran (AC-3c). Kelola: academic.classes.manage di lembaga kelas.
    Route::post('kelas/{class}/jadwal', [ScheduleController::class, 'store'])->whereUuid('class')->name('academic.schedule.store');
    Route::patch('kelas/jadwal/{entry}', [ScheduleController::class, 'update'])->whereUuid('entry')->name('academic.schedule.update');
    Route::delete('kelas/jadwal/{entry}', [ScheduleController::class, 'destroy'])->whereUuid('entry')->name('academic.schedule.destroy');

    // Kelas saya (AC-3d): wali kelas & guru pengampu yang sedang login. Akses dari data penugasan mengajar.
    Route::get('kelas-saya', [MyClassesController::class, 'index'])->name('academic.my-classes.index');
    Route::get('kelas-saya/{class}', [MyClassesController::class, 'show'])->whereUuid('class')->name('academic.my-classes.show');
    Route::get('kelas-saya/{class}/unduh', [MyClassesController::class, 'export'])->whereUuid('class')->name('academic.my-classes.export');

    // Presensi harian (AC-4a). Operator: academic.classes.manage; wali kelas: lewat Kelas saya.
    Route::get('kelas/{class}/presensi', [AttendanceController::class, 'show'])->whereUuid('class')->name('academic.attendance.show');
    Route::post('kelas/{class}/presensi', [AttendanceController::class, 'store'])->whereUuid('class')->name('academic.attendance.store');
    Route::get('kelas-saya/{class}/presensi', [AttendanceController::class, 'showMine'])->whereUuid('class')->name('academic.my-attendance.show');
    Route::post('kelas-saya/{class}/presensi', [AttendanceController::class, 'storeMine'])->whereUuid('class')->name('academic.my-attendance.store');
    // Presensi per pertemuan (AC-4c). Operator: academic.classes.manage; guru pengampu: lewat Kelas saya.
    Route::get('kelas/jadwal/{entry}/presensi', [AttendanceController::class, 'showLesson'])->whereUuid('entry')->name('academic.lesson-attendance.show');
    Route::post('kelas/jadwal/{entry}/presensi', [AttendanceController::class, 'storeLesson'])->whereUuid('entry')->name('academic.lesson-attendance.store');
    Route::get('kelas-saya/jadwal/{entry}/presensi', [AttendanceController::class, 'showMyLesson'])->whereUuid('entry')->name('academic.my-lesson-attendance.show');
    Route::post('kelas-saya/jadwal/{entry}/presensi', [AttendanceController::class, 'storeMyLesson'])->whereUuid('entry')->name('academic.my-lesson-attendance.store');
    // Rekap presensi (AC-4b). Lihat: izin lihat siswa; wali kelas: lewat Kelas saya.
    Route::get('kelas/{class}/rekap-presensi', [AttendanceController::class, 'recap'])->whereUuid('class')->name('academic.attendance.recap');

    // Presensi mengajar & jadwal piket (PRD-002 §20, AC-6a). Petugas: academic.teaching.manage atau guru piket hari ini.
    Route::get('akademik/presensi-mengajar', [TeachingController::class, 'index'])->name('academic.teaching.index');
    Route::post('akademik/presensi-mengajar/{entry}', [TeachingController::class, 'store'])->whereUuid('entry')->name('academic.teaching.store');
    Route::get('akademik/perlu-pengganti', [TeachingController::class, 'substitutes'])->name('academic.substitutes.index');
    Route::post('akademik/presensi-mengajar/{entry}/batalkan-pengganti', [TeachingController::class, 'clearSubstitute'])->whereUuid('entry')->name('academic.substitutes.clear');
    Route::post('kelas-saya/jadwal/{entry}/mengajar', [TeachingController::class, 'markTaught'])->whereUuid('entry')->name('academic.my-teaching.store');
    Route::get('akademik/jadwal-piket', [DutyRosterController::class, 'index'])->name('academic.duty.index');
    Route::post('akademik/jadwal-piket', [DutyRosterController::class, 'update'])->name('academic.duty.update');

    // Lembar nilai (PRD-002 §19 AC-5b): pintu operator/pemantau dan pintu guru pengampu (Kelas saya).
    Route::get('kelas/mapel/{classSubject}/nilai', [GradeController::class, 'show'])->whereUuid('classSubject')->name('academic.grades.show');
    Route::post('kelas/mapel/{classSubject}/nilai', [GradeController::class, 'store'])->whereUuid('classSubject')->name('academic.grades.store');
    Route::post('kelas/mapel/{classSubject}/nilai/final', [GradeController::class, 'finalize'])->whereUuid('classSubject')->name('academic.grades.finalize');
    Route::post('kelas/mapel/{classSubject}/nilai/buka-kembali', [GradeController::class, 'reopen'])->whereUuid('classSubject')->name('academic.grades.reopen');
    Route::post('kelas/mapel/{classSubject}/nilai/aturan-terbaru', [GradeController::class, 'applyRules'])->whereUuid('classSubject')->name('academic.grades.apply-rules');
    Route::get('kelas-saya/mapel/{classSubject}/nilai', [GradeController::class, 'showMine'])->whereUuid('classSubject')->name('academic.my-grades.show');
    Route::post('kelas-saya/mapel/{classSubject}/nilai', [GradeController::class, 'storeMine'])->whereUuid('classSubject')->name('academic.my-grades.store');
    Route::post('kelas-saya/mapel/{classSubject}/nilai/final', [GradeController::class, 'finalizeMine'])->whereUuid('classSubject')->name('academic.my-grades.finalize');

    // Leger nilai kelas (PRD-002 §19 AC-5c): pemantau/pengelola kelas dan wali kelas (Kelas saya).
    Route::get('kelas/{class}/leger', [GradeLedgerController::class, 'show'])->whereUuid('class')->name('academic.ledger.show');
    Route::get('kelas/{class}/leger/unduh', [GradeLedgerController::class, 'export'])->whereUuid('class')->name('academic.ledger.export');
    Route::get('kelas-saya/{class}/leger', [GradeLedgerController::class, 'showMine'])->whereUuid('class')->name('academic.my-ledger.show');
    Route::get('kelas-saya/{class}/leger/unduh', [GradeLedgerController::class, 'exportMine'])->whereUuid('class')->name('academic.my-ledger.export');

    // Rapor (PRD-002 §19 AC-5d): daftar + catatan wali kelas, dan halaman cetak.
    Route::get('kelas/{class}/rapor', [ReportCardController::class, 'index'])->whereUuid('class')->name('academic.reports.index');
    Route::post('kelas/{class}/rapor/catatan', [ReportCardController::class, 'saveNotes'])->whereUuid('class')->name('academic.reports.notes');
    Route::get('kelas/{class}/rapor/cetak', [ReportCardController::class, 'print'])->whereUuid('class')->name('academic.reports.print');
    Route::get('kelas-saya/{class}/rapor', [ReportCardController::class, 'indexMine'])->whereUuid('class')->name('academic.my-reports.index');
    Route::post('kelas-saya/{class}/rapor/catatan', [ReportCardController::class, 'saveNotesMine'])->whereUuid('class')->name('academic.my-reports.notes');
    Route::get('kelas-saya/{class}/rapor/cetak', [ReportCardController::class, 'printMine'])->whereUuid('class')->name('academic.my-reports.print');
    Route::get('kelas-saya/{class}/rekap-presensi', [AttendanceController::class, 'recapMine'])->whereUuid('class')->name('academic.my-attendance.recap');

    // Proses akhir tahun per kelas (AC-2): naik, tinggal kelas, lulus. Kelola: academic.classes.manage
    // (+ academic.students.manage untuk lulus) di lembaga kelas.
    Route::get('kelas/{class}/akhir-tahun', [ClassYearEndController::class, 'show'])->whereUuid('class')->name('academic.classes.year-end');
    Route::post('kelas/{class}/akhir-tahun', [ClassYearEndController::class, 'store'])->whereUuid('class')->name('academic.classes.year-end.store');
});
