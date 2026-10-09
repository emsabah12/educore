<?php

use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Modules\Core\Domain\Identity\User;

/*
 * Login global dengan email ATAU username (PRD-000 §6, F2a).
 */

test('login screen can be rendered', function () {
    $this->get(route('login'))->assertOk();
});

test('users can authenticate with their email', function () {
    $user = User::factory()->create(['email' => 'guru@example.com']);

    $response = $this->post(route('login.store'), [
        'login' => 'guru@example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can authenticate with their username', function () {
    $user = User::factory()->create(['username' => 'budi.santoso']);

    $this->post(route('login.store'), [
        'login' => 'budi.santoso',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('login is not case sensitive for email and username', function () {
    $user = User::factory()->create(['email' => 'guru@example.com', 'username' => 'budi.santoso']);

    $this->post(route('login.store'), ['login' => 'Guru@Example.COM', 'password' => 'password']);
    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'));

    $this->post(route('login.store'), ['login' => 'BUDI.Santoso', 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'login' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'login' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['login' => __('auth.failed')]);

    $this->assertGuest();
});

test('inactive users can not authenticate and get the same generic error', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), [
        'login' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors(['login' => __('auth.failed')]);

    $this->assertGuest();
});

test('unknown accounts get the same generic error', function () {
    $this->post(route('login.store'), [
        'login' => 'tidak-ada@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors(['login' => __('auth.failed')]);

    $this->assertGuest();
});

test('login field is required', function () {
    $this->post(route('login.store'), [
        'login' => '',
        'password' => 'password',
    ])->assertSessionHasErrors('login');

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));
    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'login' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
