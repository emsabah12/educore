<?php

use Illuminate\Support\Facades\Route;
use Modules\Dormitory\Http\Controllers\BuildingController;
use Modules\Dormitory\Http\Controllers\DormitoryController;
use Modules\Dormitory\Http\Controllers\PlacementController;
use Modules\Dormitory\Http\Controllers\ResidentController;
use Modules\Dormitory\Http\Controllers\RoomController;
use Modules\Dormitory\Http\Controllers\RoomResourceController;

/*
 * Route web modul Dormitory (PRD-008 §10, §15 DM-1). Dimuat oleh ModuleServiceProvider dengan middleware group "web".
 * Semua halaman asrama bekerja di dalam yayasan, jadi wajib memakai `work.context`.
 */
Route::middleware(['auth', 'work.context'])->group(function () {
    // Daftar & detail asrama. Lihat: dormitory.facilities.view; kelola: dormitory.facilities.manage di node pemilik.
    Route::get('asrama', [DormitoryController::class, 'index'])->name('dormitory.dormitories.index');
    Route::post('asrama', [DormitoryController::class, 'store'])->name('dormitory.dormitories.store');

    // Penghuni (DM-2). Lihat: dormitory.residents.view; kelola: dormitory.residents.manage di node pemilik.
    Route::get('asrama/penghuni', [ResidentController::class, 'index'])->name('dormitory.residents.index');
    Route::get('asrama/penghuni/{membership}', [ResidentController::class, 'person'])->whereUuid('membership')->name('dormitory.residents.person');
    Route::get('asrama/kamar/{room}/calon', [PlacementController::class, 'candidates'])->whereUuid('room')->middleware('throttle:60,1')->name('dormitory.placements.candidates');
    Route::post('asrama/kamar/{room}/penghuni', [PlacementController::class, 'store'])->whereUuid('room')->name('dormitory.placements.store');
    Route::post('asrama/penempatan/{placement}/check-in', [PlacementController::class, 'checkIn'])->whereUuid('placement')->name('dormitory.placements.check-in');
    Route::post('asrama/penempatan/{placement}/batalkan', [PlacementController::class, 'cancel'])->whereUuid('placement')->name('dormitory.placements.cancel');
    Route::post('asrama/penempatan/{placement}/check-out', [PlacementController::class, 'checkOut'])->whereUuid('placement')->name('dormitory.placements.check-out');
    Route::post('asrama/penempatan/{placement}/bed-loker', [PlacementController::class, 'resources'])->whereUuid('placement')->name('dormitory.placements.resources');

    // Gedung, kamar, bed/loker — didaftarkan sebelum `asrama/{dormitory}`.
    Route::patch('asrama/gedung/{building}', [BuildingController::class, 'update'])->whereUuid('building')->name('dormitory.buildings.update');
    Route::post('asrama/gedung/{building}/status', [BuildingController::class, 'status'])->whereUuid('building')->name('dormitory.buildings.status');
    Route::post('asrama/gedung/{building}/kamar', [RoomController::class, 'store'])->whereUuid('building')->name('dormitory.rooms.store');

    Route::get('asrama/kamar/{room}', [RoomController::class, 'show'])->whereUuid('room')->name('dormitory.rooms.show');
    Route::patch('asrama/kamar/{room}', [RoomController::class, 'update'])->whereUuid('room')->name('dormitory.rooms.update');
    Route::post('asrama/kamar/{room}/status', [RoomController::class, 'status'])->whereUuid('room')->name('dormitory.rooms.status');
    Route::post('asrama/kamar/{room}/bed-loker', [RoomResourceController::class, 'store'])->whereUuid('room')->name('dormitory.resources.store');

    Route::patch('asrama/{kind}/{resource}', [RoomResourceController::class, 'update'])->whereIn('kind', ['bed', 'loker'])->whereUuid('resource')->name('dormitory.resources.update');
    Route::post('asrama/{kind}/{resource}/status', [RoomResourceController::class, 'status'])->whereIn('kind', ['bed', 'loker'])->whereUuid('resource')->name('dormitory.resources.status');
    Route::delete('asrama/{kind}/{resource}', [RoomResourceController::class, 'destroy'])->whereIn('kind', ['bed', 'loker'])->whereUuid('resource')->name('dormitory.resources.destroy');

    Route::get('asrama/{dormitory}', [DormitoryController::class, 'show'])->whereUuid('dormitory')->name('dormitory.dormitories.show');
    Route::patch('asrama/{dormitory}', [DormitoryController::class, 'update'])->whereUuid('dormitory')->name('dormitory.dormitories.update');
    Route::post('asrama/{dormitory}/status', [DormitoryController::class, 'status'])->whereUuid('dormitory')->name('dormitory.dormitories.status');
    Route::post('asrama/{dormitory}/gedung', [BuildingController::class, 'store'])->whereUuid('dormitory')->name('dormitory.buildings.store');
});
