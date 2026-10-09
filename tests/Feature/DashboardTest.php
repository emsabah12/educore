<?php

use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Person;
use Tests\Support\CoreFixtures;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('members can visit the dashboard', function () {
    $user = User::factory()->create();
    CoreFixtures::member($user, CoreFixtures::tenant());

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('shared user data uses the person name and hides sensitive fields', function () {
    $user = User::factory()
        ->for(Person::factory()->state(['name' => 'Siti Aminah']))
        ->create(['username' => 'siti.aminah']);
    CoreFixtures::member($user, CoreFixtures::tenant());

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', 'Siti Aminah')
            ->where('auth.user.username', 'siti.aminah')
            ->where('auth.user.is_superadmin', false)
            ->missing('auth.user.password')
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.remember_token'),
        );
});
