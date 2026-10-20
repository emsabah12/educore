<?php

use Illuminate\Support\Facades\Route;
use Modules\Academic\Http\Controllers\CalendarController;
use Modules\Academic\Http\Controllers\ClassController;
use Modules\Academic\Http\Controllers\StudentActionController;
use Modules\Academic\Http\Controllers\StudentController;
use Modules\Academic\Http\Controllers\StudentGuardianController;

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
    Route::patch('akademik/kalender/{calendar}', [CalendarController::class, 'renameCalendar'])->whereUuid('calendar')->name('academic.calendar.update');
    Route::post('akademik/kalender/{calendar}/tahun-ajaran', [CalendarController::class, 'storeYear'])->whereUuid('calendar')->name('academic.years.store');
    Route::patch('akademik/tahun-ajaran/{year}', [CalendarController::class, 'updateYear'])->whereUuid('year')->name('academic.years.update');
    Route::post('akademik/tahun-ajaran/{year}/berjalan', [CalendarController::class, 'setCurrent'])->whereUuid('year')->name('academic.years.current');

    // Siswa (PRD-002 §6). Daftar & detail (AC-1c-a): pemilik izin lihat siswa, disaring per pohon lembaga.
    // Tambah (AC-1b): Operator Akademik di lembaganya, Admin Yayasan di semua lembaga.
    // `siswa/tambah` didaftarkan sebelum `siswa/{student}`.
    Route::get('siswa', [StudentController::class, 'index'])->name('academic.students.index');
    Route::get('siswa/tambah', [StudentController::class, 'create'])->name('academic.students.create');
    Route::post('siswa', [StudentController::class, 'store'])->name('academic.students.store');
    Route::get('siswa/{student}', [StudentController::class, 'show'])->whereUuid('student')->name('academic.students.show');

    // Aksi di detail siswa (AC-1c-b1). Hak akses dicek per aksi di controller (OD-AC-09, §5.3).
    Route::patch('siswa/{student}', [StudentActionController::class, 'updateProfile'])->whereUuid('student')->name('academic.students.update');
    Route::post('siswa/{student}/pendaftaran', [StudentActionController::class, 'enroll'])->whereUuid('student')->name('academic.enrollments.store');
    Route::patch('siswa/{student}/pendaftaran/{enrollment}', [StudentActionController::class, 'updateEnrollment'])
        ->whereUuid(['student', 'enrollment'])->name('academic.enrollments.update');
    Route::post('siswa/{student}/pendaftaran/{enrollment}/tutup', [StudentActionController::class, 'closeEnrollment'])
        ->whereUuid(['student', 'enrollment'])->name('academic.enrollments.close');

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

    // Kelas/rombel (AC-1d-a, PRD-002 §6). Lihat: izin lihat siswa; kelola: academic.classes.manage di lembaga kelas.
    Route::get('kelas', [ClassController::class, 'index'])->name('academic.classes.index');
    Route::post('kelas', [ClassController::class, 'store'])->name('academic.classes.store');
    Route::patch('kelas/{class}', [ClassController::class, 'update'])->whereUuid('class')->name('academic.classes.update');
    Route::post('kelas/{class}/status', [ClassController::class, 'status'])->whereUuid('class')->name('academic.classes.status');
    Route::delete('kelas/{class}', [ClassController::class, 'destroy'])->whereUuid('class')->name('academic.classes.destroy');
});
