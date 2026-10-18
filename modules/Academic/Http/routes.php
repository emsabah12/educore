<?php

use Illuminate\Support\Facades\Route;
use Modules\Academic\Http\Controllers\CalendarController;
use Modules\Academic\Http\Controllers\StudentController;

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
});
