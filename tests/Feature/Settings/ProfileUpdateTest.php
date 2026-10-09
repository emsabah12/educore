<?php

use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Person;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
});

test('profile update changes the person name and the user email', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => '  Ahmad Fauzi ',
            'email' => 'Ahmad.Fauzi@Example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect(Person::query()->findOrFail($user->person_id)->name)->toBe('Ahmad Fauzi')
        ->and($user->refresh()->email)->toBe('ahmad.fauzi@example.com');
});

test('email must be unique regardless of letter case', function () {
    User::factory()->create(['email' => 'dipakai@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => 'Nama Baru',
            'email' => 'DIPAKAI@example.com',
        ])
        ->assertSessionHasErrors('email')
        ->assertRedirect(route('profile.edit'));
});

test('name is required', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => '',
            'email' => $user->email,
        ])
        ->assertSessionHasErrors('name');
});

test('users can not delete their own account', function () {
    $user = User::factory()->create();

    // Route hapus akun sudah dihapus (PRD-000 OD-06); URL yang sama hanya menerima GET/PATCH.
    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertMethodNotAllowed();

    expect($user->fresh())->not->toBeNull();
});
