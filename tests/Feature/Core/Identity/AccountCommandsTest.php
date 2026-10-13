<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Membership;
use Tests\Support\CoreFixtures;

/*
 * Perintah pembuatan akun pertama untuk production (OD-19).
 */

const PERTANYAAN_EMAIL = 'Email';

/**
 * Jawab semua pertanyaan akun baru.
 */
function jawabAkunBaru(mixed $command, string $email, string $username, string $password, ?string $confirmation = null): mixed
{
    return $command
        ->expectsQuestion(PERTANYAAN_EMAIL, $email)
        ->expectsQuestion('Nama lengkap', 'Ahmad Fauzi')
        ->expectsQuestion('Username (boleh dikosongkan)', $username)
        ->expectsQuestion('Kata sandi', $password)
        ->expectsQuestion('Ulangi kata sandi', $confirmation ?? $password);
}

// ── Superadmin ────────────────────────────────────────────────────────────────

test('membuat Superadmin baru dengan kata sandi tersembunyi dan tercatat di audit log', function () {
    jawabAkunBaru($this->artisan('educore:create-superadmin'), 'Root@Yayasan.ID', 'root', 'Sandi-Kuat-2026')
        ->expectsOutputToContain('Superadmin root@yayasan.id berhasil dibuat.')
        ->assertSuccessful();

    $user = User::query()->where('email', 'root@yayasan.id')->sole();

    expect($user->is_superadmin)->toBeTrue()
        ->and($user->username)->toBe('root')
        ->and(Hash::check('Sandi-Kuat-2026', $user->password))->toBeTrue()
        ->and($user->person->name)->toBe('Ahmad Fauzi')
        ->and(DB::table('audit_logs')->where('action', AuditAction::ACCOUNT_CREATED)->where('subject_id', $user->id)->exists())->toBeTrue();

    $semua = DB::table('audit_logs')->pluck('changes')->implode(' ');
    expect($semua)->not->toContain('Sandi-Kuat-2026');
});

test('kata sandi yang tidak sama atau email salah ditolak tanpa membuat apa pun', function () {
    jawabAkunBaru($this->artisan('educore:create-superadmin'), 'root@yayasan.id', 'root', 'Sandi-Kuat-2026', 'Sandi-Lain-2026')
        ->assertFailed();

    jawabAkunBaru($this->artisan('educore:create-superadmin'), 'bukan-email', 'root', 'Sandi-Kuat-2026')
        ->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(DB::table('persons')->count())->toBe(0);
});

test('username dengan huruf tidak sah atau yang sudah dipakai ditolak', function () {
    User::factory()->create(['username' => 'root']);

    jawabAkunBaru($this->artisan('educore:create-superadmin'), 'baru@yayasan.id', 'root', 'Sandi-Kuat-2026')
        ->assertFailed();
    jawabAkunBaru($this->artisan('educore:create-superadmin'), 'baru@yayasan.id', 'Root Admin', 'Sandi-Kuat-2026')
        ->assertFailed();

    expect(User::query()->where('email', 'baru@yayasan.id')->exists())->toBeFalse();
});

test('akun yang sudah ada bisa dijadikan Superadmin setelah konfirmasi', function () {
    $user = User::factory()->create(['email' => 'ada@yayasan.id']);

    $this->artisan('educore:create-superadmin')
        ->expectsQuestion(PERTANYAAN_EMAIL, 'ada@yayasan.id')
        ->expectsConfirmation('Akun ada@yayasan.id sudah ada. Jadikan Superadmin?', 'yes')
        ->assertSuccessful();

    expect($user->refresh()->is_superadmin)->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', AuditAction::SUPERADMIN_GRANTED)->count())->toBe(1);
});

// ── Admin Yayasan ─────────────────────────────────────────────────────────────

test('membuat Admin Yayasan baru yang langsung bisa mengelola yayasan', function () {
    $this->seed(FirstTenantSeeder::class);
    CoreFixtures::syncAccess();

    jawabAkunBaru($this->artisan('educore:create-admin'), 'admin@yayasan.id', 'admin', 'Sandi-Kuat-2026')
        ->expectsOutputToContain('admin@yayasan.id sekarang Admin Yayasan')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@yayasan.id')->sole();
    $membership = Membership::query()->where('person_id', $admin->person_id)->sole();

    expect($admin->is_superadmin)->toBeFalse()
        ->and(DB::table('membership_roles')->where('membership_id', $membership->id)->count())->toBe(1);

    // Bisa langsung membuka halaman yang butuh izin tingkat Yayasan.
    $this->actingAs($admin)->get(route('organizations.index'))->assertOk();
    $this->actingAs($admin)->get(route('audit.index'))->assertOk();
});

test('kode yayasan yang tidak ada ditolak dengan daftar kode yang tersedia', function () {
    $this->seed(FirstTenantSeeder::class);

    $this->artisan('educore:create-admin', ['tenant' => 'salah'])
        ->expectsOutputToContain('Yayasan dengan kode SALAH tidak ditemukan. Kode yang ada: YAYASAN.')
        ->assertFailed();
});

test('akun yang sudah ada dijadikan Admin Yayasan setelah konfirmasi; menolak konfirmasi tidak mengubah apa pun', function () {
    $this->seed(FirstTenantSeeder::class);
    CoreFixtures::syncAccess();
    $user = User::factory()->create(['email' => 'guru@yayasan.id']);

    $this->artisan('educore:create-admin')
        ->expectsQuestion(PERTANYAAN_EMAIL, 'guru@yayasan.id')
        ->expectsConfirmation('Akun guru@yayasan.id sudah ada. Jadikan Admin Yayasan di Yayasan (nama resmi belum ditetapkan)?', 'no')
        ->assertFailed();

    expect(Membership::query()->where('person_id', $user->person_id)->exists())->toBeFalse();

    $this->artisan('educore:create-admin')
        ->expectsQuestion(PERTANYAAN_EMAIL, 'guru@yayasan.id')
        ->expectsConfirmation('Akun guru@yayasan.id sudah ada. Jadikan Admin Yayasan di Yayasan (nama resmi belum ditetapkan)?', 'yes')
        ->assertSuccessful();

    expect(DB::table('audit_logs')->where('action', AuditAction::ROLE_GRANTED)->where('changes->role', CoreAccess::ROLE_ADMIN_YAYASAN)->count())->toBe(1);
});

test('tanpa katalog akses, admin tidak dibuat sama sekali dan diberi petunjuk', function () {
    $this->seed(FirstTenantSeeder::class);

    jawabAkunBaru($this->artisan('educore:create-admin'), 'admin@yayasan.id', 'admin', 'Sandi-Kuat-2026')
        ->expectsOutputToContain('educore:sync-access')
        ->assertFailed();

    expect(User::query()->where('email', 'admin@yayasan.id')->exists())->toBeFalse();
});
