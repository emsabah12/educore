<?php

use Illuminate\Support\Facades\Route;

/*
 * Catatan: sengaja TIDAK memakai Route::redirect(). Route::redirect menerima semua
 * HTTP method, dan Wayfinder belum bisa membuat tipe TypeScript untuk sebagian
 * method tersebut sehingga `npm run types:check` gagal. Route GET biasa cukup.
 */

// Halaman depan langsung ke beranda; tamu otomatis diarahkan ke halaman login.
Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
