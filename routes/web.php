<?php

use Illuminate\Support\Facades\Route;

// Halaman depan langsung ke beranda; tamu otomatis diarahkan ke halaman login.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
