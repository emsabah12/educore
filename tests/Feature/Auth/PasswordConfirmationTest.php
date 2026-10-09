<?php

use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Domain\Identity\User;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/confirm-password'));
});

test('password confirmation requires authentication', function () {
    $this->get(route('password.confirm'))->assertRedirect(route('login'));
});
