<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Identity\AuthenticateUser;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;

/*
 * Identitas kanonik: Person → User (PRD-000 §5, F2a).
 */

test('person and user ids are UUIDv7', function () {
    $user = User::factory()->create();

    // Karakter ke-15 UUID adalah nomor versinya.
    expect(Str::isUuid($user->id))->toBeTrue()
        ->and($user->id[14])->toBe('7')
        ->and($user->person_id[14])->toBe('7');
});

test('a user always belongs to a person', function () {
    $user = User::factory()
        ->for(Person::factory()->state(['name' => 'Siti Aminah', 'gender' => Gender::Female]))
        ->create();

    $person = $user->person()->firstOrFail();

    expect($person->name)->toBe('Siti Aminah')
        ->and($person->gender)->toBe(Gender::Female);
});

test('one person can only have one user account', function () {
    $user = User::factory()->create();

    expect(fn () => User::factory()->create(['person_id' => $user->person_id]))
        ->toThrow(QueryException::class);
});

test('email and username are stored in lowercase', function () {
    $user = User::factory()->create(['email' => '  Guru@Example.COM ', 'username' => 'Budi.Santoso']);

    expect($user->refresh()->email)->toBe('guru@example.com')
        ->and($user->username)->toBe('budi.santoso');
});

test('database rejects uppercase email and invalid username even when the model is bypassed', function () {
    $person = Person::factory()->create();

    $insertUser = fn (array $overrides) => DB::table('users')->insert([
        'id' => (string) Str::uuid7(),
        'person_id' => $person->id,
        'email' => 'valid@example.com',
        'username' => null,
        'password' => 'x',
        ...$overrides,
    ]);

    expect(fn () => $insertUser(['email' => 'Huruf@Besar.com']))->toThrow(QueryException::class);
});

test('database rejects username with invalid characters', function () {
    $person = Person::factory()->create();

    expect(fn () => DB::table('users')->insert([
        'id' => (string) Str::uuid7(),
        'person_id' => $person->id,
        'email' => 'valid@example.com',
        'username' => 'pakai@at',
        'password' => 'x',
    ]))->toThrow(QueryException::class);
});

test('status and superadmin flag can not be mass assigned', function () {
    $user = new User([
        'email' => 'coba@example.com',
        'status' => 'INACTIVE',
        'is_superadmin' => true,
    ]);

    expect($user->isActive())->toBeTrue()
        ->and($user->is_superadmin)->toBeFalse();
});

test('authenticate user service rejects blank input and inactive accounts', function () {
    $service = app(AuthenticateUser::class);
    $active = User::factory()->create(['email' => 'aktif@example.com']);
    User::factory()->inactive()->create(['email' => 'nonaktif@example.com']);

    expect($service->handle('', 'password'))->toBeNull()
        ->and($service->handle('aktif@example.com', ''))->toBeNull()
        ->and($service->handle('nonaktif@example.com', 'password'))->toBeNull()
        ->and($service->handle('aktif@example.com', 'password')?->id)->toBe($active->id);
});
