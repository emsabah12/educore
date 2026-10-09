<?php

use Modules\Core\Domain\Identity\User;

test('home page redirects to the dashboard', function () {
    $this->get(route('home'))->assertRedirect(route('dashboard'));
});

test('settings page redirects to the profile settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings')->assertRedirect(route('profile.edit'));
});

test('home and settings shortcuts only accept GET', function () {
    // Mencegah kembali ke Route::redirect() yang menerima semua method
    // dan membuat `npm run types:check` gagal (lihat routes/web.php).
    $user = User::factory()->create();

    $this->actingAs($user)->post('/')->assertMethodNotAllowed();
    $this->actingAs($user)->post('/settings')->assertMethodNotAllowed();
});
