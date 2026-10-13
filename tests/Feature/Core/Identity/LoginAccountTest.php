<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Identity\ManageLoginAccount;
use Modules\Core\Application\Identity\TemporaryPassword;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\Exceptions\AccountException;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Identity\UserStatus;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Kelola akun login oleh Admin Yayasan (PRD-000 §4.7 F5a, OD-20 s.d. OD-22).
 */

const AKSES_SANDI_BARU = 'Sandi-Baru#2026x';

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->membership = aksesAnggotaBaru($this->tenant, 'Ani Rahma');
});

/**
 * Orang baru yang terdaftar di yayasan tetapi belum punya akun (seperti pegawai dari HR-1b).
 */
function aksesAnggotaBaru(Tenant $tenant, string $name): Membership
{
    $person = Person::query()->create(['name' => $name]);

    return app(GrantMembership::class)->handle($person->id, $tenant->id);
}

function aksesMembershipMilik(User $user, Tenant $tenant): Membership
{
    return Membership::query()->where('person_id', $user->person_id)->where('tenant_id', $tenant->id)->sole();
}

/**
 * @return list<object>
 */
function aksesAudit(string $action): array
{
    return DB::table('audit_logs')->where('action', $action)->orderBy('created_at')->get()->all();
}

function aksesSesi(User $user): string
{
    $id = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'payload' => 'uji',
        'last_activity' => time(),
    ]);

    return $id;
}

function aksesAkunUntuk(Membership $membership): User
{
    return User::query()->where('person_id', $membership->person_id)->sole();
}

// ── Kata sandi sementara ──────────────────────────────────────────────────────

test('kata sandi sementara 12 karakter selalu memuat huruf besar, huruf kecil, angka, dan simbol', function () {
    $samples = [];

    for ($i = 0; $i < 50; $i++) {
        $password = TemporaryPassword::generate();
        $samples[] = $password;

        expect(strlen($password))->toBe(12)
            ->and($password)->toMatch('/[A-Z]/')
            ->and($password)->toMatch('/[a-z]/')
            ->and($password)->toMatch('/[2-9]/')
            ->and($password)->toMatch('/[!@#$%*?\-]/')
            ->and($password)->not->toMatch('/[0O1lI]/');
    }

    expect(array_unique($samples))->toHaveCount(50);
});

// ── Buat akun ─────────────────────────────────────────────────────────────────

test('admin yayasan membuat akun login dengan sandi sementara yang wajib diganti', function () {
    $this->actingAs($this->admin)
        ->post(route('access.accounts.store', $this->membership->id), ['username' => '  Ani.Rahma ', 'email' => ''])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $user = aksesAkunUntuk($this->membership);

    expect($user->username)->toBe('ani.rahma')
        ->and($user->email)->toBeNull()
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->is_superadmin)->toBeFalse();

    $audit = aksesAudit(AuditAction::ACCOUNT_CREATED);

    expect($audit)->toHaveCount(1)
        ->and($audit[0]->tenant_id)->toBe($this->tenant->id)
        ->and($audit[0]->actor_user_id)->toBe($this->admin->id)
        ->and($audit[0]->subject_id)->toBe($user->id);
});

test('kata sandi sementara bisa dipakai login dan tidak pernah masuk audit log', function () {
    $issued = app(ManageLoginAccount::class)->provision($this->tenant->id, $this->membership->id, 'ani.rahma', 'Ani@Example.com');
    $user = aksesAkunUntuk($this->membership);

    expect($issued->login)->toBe('ani.rahma')
        ->and(Hash::check($issued->password, $user->password))->toBeTrue()
        ->and($user->email)->toBe('ani@example.com')
        ->and(DB::table('audit_logs')->where('changes', 'like', '%'.$issued->password.'%')->exists())->toBeFalse();

    $this->post(route('login.store'), ['login' => 'ani.rahma', 'password' => $issued->password]);
    $this->assertAuthenticatedAs($user);
});

test('username wajib dan formatnya diperiksa, email opsional tetapi harus valid', function () {
    $store = fn (array $data) => $this->actingAs($this->admin)
        ->post(route('access.accounts.store', $this->membership->id), $data);

    $store(['username' => ''])->assertSessionHasErrors('username');
    $store(['username' => 'Ani Rahma!'])->assertSessionHasErrors('username');
    $store(['username' => 'ab'])->assertSessionHasErrors('username');
    $store(['username' => 'ani.rahma', 'email' => 'bukan-email'])->assertSessionHasErrors('email');

    expect(User::query()->where('person_id', $this->membership->person_id)->exists())->toBeFalse();
});

test('username dan email tidak boleh dipakai akun lain, tanpa peduli huruf besar', function () {
    $this->actingAs($this->admin)
        ->post(route('access.accounts.store', $this->membership->id), ['username' => 'KEPALA.UNIT1'])
        ->assertSessionHasErrors(['username' => 'Username ini sudah dipakai akun lain.']);

    $this->actingAs($this->admin)
        ->post(route('access.accounts.store', $this->membership->id), ['username' => 'ani.rahma', 'email' => 'GURU@educore.test'])
        ->assertSessionHasErrors(['email' => 'Email ini sudah dipakai akun lain.']);
});

test('anggota yang sudah punya akun tidak bisa dibuatkan akun lagi', function () {
    $guru = DevAccounts::user('guru');

    $this->actingAs($this->admin)
        ->post(route('access.accounts.store', aksesMembershipMilik($guru, $this->tenant)->id), ['username' => 'guru.baru'])
        ->assertSessionHasErrors(['form' => 'Pegawai ini sudah punya akun login.']);
});

// ── Hak akses & cakupan ───────────────────────────────────────────────────────

test('selain admin yayasan ditolak, termasuk kepala lembaga dan pimpinan', function (string $username) {
    $membership = $this->membership->id;

    $this->actingAs(DevAccounts::user($username))
        ->post(route('access.accounts.store', $membership), ['username' => 'ani.rahma'])
        ->assertForbidden();
    $this->actingAs(DevAccounts::user($username))
        ->post(route('access.accounts.reset', aksesMembershipMilik(DevAccounts::user('kepala.smk'), $this->tenant)->id))
        ->assertForbidden();

    expect(User::query()->where('person_id', $this->membership->person_id)->exists())->toBeFalse();
})->with(['kepala.unit1', 'pimpinan.ponpes']);

test('anggota yayasan lain atau ID asal-asalan menghasilkan 404', function () {
    $lain = aksesAnggotaBaru(CoreFixtures::tenant('YYS-B'), 'Orang Yayasan Lain');

    $this->actingAs($this->admin)
        ->post(route('access.accounts.store', $lain->id), ['username' => 'orang.lain'])
        ->assertNotFound();
    $this->actingAs($this->admin)
        ->post(route('access.accounts.store', (string) Str::uuid7()), ['username' => 'orang.lain'])
        ->assertNotFound();
    $this->actingAs($this->admin)
        ->post('/akses/anggota/bukan-uuid/akun', ['username' => 'orang.lain'])
        ->assertNotFound();
});

test('akun superadmin dan akun yang juga terdaftar di yayasan lain tidak bisa diatur dari yayasan ini', function () {
    $superadmin = DevAccounts::user('superadmin');
    CoreFixtures::member($superadmin, $this->tenant);

    $this->actingAs($this->admin)
        ->post(route('access.accounts.reset', aksesMembershipMilik($superadmin, $this->tenant)->id))
        ->assertSessionHasErrors('form');

    $guru = DevAccounts::user('guru');
    CoreFixtures::member($guru, CoreFixtures::tenant('YYS-B'));

    $this->actingAs($this->admin)
        ->post(route('access.accounts.deactivate', aksesMembershipMilik($guru, $this->tenant)->id))
        ->assertSessionHasErrors(['form' => AccountException::sharedWithOtherTenant()->getMessage()]);

    expect($guru->fresh()?->status)->toBe(UserStatus::Active);
});

// ── Wajib ganti sandi ─────────────────────────────────────────────────────────

test('akun bersandi sementara hanya bisa membuka halaman ganti sandi dan keluar', function () {
    app(ManageLoginAccount::class)->provision($this->tenant->id, $this->membership->id, 'ani.rahma', null);
    $user = aksesAkunUntuk($this->membership);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('password.force.edit'));
    $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('password.force.edit'));
    $this->actingAs($user)
        ->get(route('password.force.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/change-password'));

    $this->actingAs($user)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

test('sandi sementara diganti dengan sandi baru, lalu akun bisa bekerja normal', function () {
    $issued = app(ManageLoginAccount::class)->provision($this->tenant->id, $this->membership->id, 'ani.rahma', null);
    $user = aksesAkunUntuk($this->membership);

    $this->actingAs($user)
        ->put(route('password.force.update'), ['password' => $issued->password, 'password_confirmation' => $issued->password])
        ->assertSessionHasErrors(['password' => 'Kata sandi baru tidak boleh sama dengan kata sandi sementara.']);

    $this->actingAs($user)
        ->put(route('password.force.update'), ['password' => AKSES_SANDI_BARU, 'password_confirmation' => 'beda'])
        ->assertSessionHasErrors('password');

    $this->actingAs($user)
        ->put(route('password.force.update'), ['password' => AKSES_SANDI_BARU, 'password_confirmation' => AKSES_SANDI_BARU])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check(AKSES_SANDI_BARU, $user->password))->toBeTrue()
        ->and(aksesAudit(AuditAction::PASSWORD_CHANGED))->toHaveCount(1);

    // Tidak lagi ditahan; halaman ganti sandi mengarah ke beranda.
    $this->actingAs($user)->get(route('password.force.edit'))->assertRedirect(route('dashboard'));
    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
});

// ── Atur ulang sandi ──────────────────────────────────────────────────────────

test('atur ulang sandi membuat sandi sementara baru dan mengakhiri sesi lain akun itu', function () {
    $kepala = DevAccounts::user('kepala.smk');
    $tokenLama = $kepala->getRememberToken();
    aksesSesi($kepala);
    aksesSesi($kepala);
    $sesiOrangLain = aksesSesi($this->admin);

    $this->actingAs($this->admin)
        ->post(route('access.accounts.reset', aksesMembershipMilik($kepala, $this->tenant)->id))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $kepala->refresh();

    expect(Hash::check(DevAccountsSeeder::PASSWORD, $kepala->password))->toBeFalse()
        ->and($kepala->must_change_password)->toBeTrue()
        ->and($kepala->getRememberToken())->not->toBe($tokenLama)
        ->and(DB::table('sessions')->where('user_id', $kepala->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', $sesiOrangLain)->exists())->toBeTrue()
        ->and(aksesAudit(AuditAction::PASSWORD_RESET))->toHaveCount(1)
        ->and(aksesAudit(AuditAction::PASSWORD_RESET)[0]->tenant_id)->toBe($this->tenant->id)
        // Sandi sementara dari admin bukan "kata sandi diubah" oleh pemiliknya.
        ->and(aksesAudit(AuditAction::PASSWORD_CHANGED))->toHaveCount(0);

    $this->actingAs($kepala)->get(route('dashboard'))->assertRedirect(route('password.force.edit'));
});

test('atur ulang sandi untuk anggota tanpa akun ditolak dengan pesan jelas', function () {
    $this->actingAs($this->admin)
        ->post(route('access.accounts.reset', $this->membership->id))
        ->assertSessionHasErrors(['form' => 'Pegawai ini belum punya akun login.']);
});

// ── Nonaktifkan & aktifkan ────────────────────────────────────────────────────

test('akun yang dinonaktifkan langsung dikeluarkan dan tidak bisa login', function () {
    $kepala = DevAccounts::user('kepala.smk');
    aksesSesi($kepala);
    $membership = aksesMembershipMilik($kepala, $this->tenant)->id;

    $this->actingAs($this->admin)
        ->post(route('access.accounts.deactivate', $membership))
        ->assertSessionHasNoErrors();

    $kepala->refresh();

    expect($kepala->status)->toBe(UserStatus::Inactive)
        ->and(DB::table('sessions')->where('user_id', $kepala->id)->count())->toBe(0)
        ->and(aksesAudit(AuditAction::ACCOUNT_DEACTIVATED))->toHaveCount(1);

    // Sesi yang masih terbuka (driver lain / cookie "ingat saya") dikeluarkan di request berikutnya.
    $this->actingAs($kepala)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    $this->post(route('login.store'), ['login' => 'kepala.smk', 'password' => DevAccountsSeeder::PASSWORD]);
    $this->assertGuest();

    $this->actingAs($this->admin)
        ->post(route('access.accounts.deactivate', $membership))
        ->assertSessionHasErrors(['form' => 'Akun ini sudah nonaktif.']);
});

test('akun nonaktif bisa diaktifkan kembali dan login dengan sandi lamanya', function () {
    $kepala = DevAccounts::user('kepala.smk');
    $membership = aksesMembershipMilik($kepala, $this->tenant)->id;

    $this->actingAs($this->admin)->post(route('access.accounts.deactivate', $membership));
    $this->actingAs($this->admin)
        ->post(route('access.accounts.activate', $membership))
        ->assertSessionHasNoErrors();

    expect($kepala->fresh()?->status)->toBe(UserStatus::Active)
        ->and(aksesAudit(AuditAction::ACCOUNT_ACTIVATED))->toHaveCount(1);

    $this->actingAs($this->admin)
        ->post(route('access.accounts.activate', $membership))
        ->assertSessionHasErrors(['form' => 'Akun ini sudah aktif.']);

    auth()->logout();
    $this->post(route('login.store'), ['login' => 'kepala.smk', 'password' => DevAccountsSeeder::PASSWORD]);
    $this->assertAuthenticatedAs($kepala);
});

test('admin tidak bisa menonaktifkan akunnya sendiri', function () {
    $this->actingAs($this->admin)
        ->post(route('access.accounts.deactivate', aksesMembershipMilik($this->admin, $this->tenant)->id))
        ->assertSessionHasErrors(['form' => 'Anda tidak bisa menonaktifkan akun Anda sendiri.']);

    expect($this->admin->fresh()?->status)->toBe(UserStatus::Active);
});

// ── Profil: email opsional ────────────────────────────────────────────────────

test('akun yang punya username boleh mengosongkan email di profil', function () {
    $this->actingAs(DevAccounts::user('guru'))
        ->patch(route('profile.update'), ['name' => 'Guru MA & MDA', 'email' => ''])
        ->assertSessionHasNoErrors();

    expect(DevAccounts::user('guru')->email)->toBeNull();
});

test('database menolak akun tanpa email dan tanpa username', function () {
    $user = DevAccounts::user('guru');

    // DB::transaction di dalam transaksi test = savepoint, sehingga kegagalan tidak merusak test.
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['email' => null, 'username' => null])))
        ->toThrow(QueryException::class);
});

// ── Bagian "Akses login" di detail pegawai ────────────────────────────────────

test('detail pegawai memuat bagian akses login hanya untuk admin yayasan', function () {
    $this->seed(HrCatalogSeeder::class);

    $katalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()
        ->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');

    $employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id,
        membershipId: null,
        name: 'Budi Pegawai',
        gender: Gender::Male,
        birthPlace: null,
        birthDate: null,
        phone: null,
        address: null,
        employeeNumber: null,
        nuptk: null,
        educationLevel: null,
        educationMajor: null,
        employmentTypeId: $katalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null,
        startDate: '2020-07-01',
        organizationId: CoreFixtures::node($this->tenant, 'U1-MTS')->id,
        positionId: $katalog(CatalogKind::Position, 'GURU'),
    ));

    $this->actingAs($this->admin)
        ->get(route('hr.employees.show', $employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('access.membershipId', $employee->membership_id)
            ->where('access.account', null)
            ->where('access.restriction', null)
            ->where('access.isSelf', false));

    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('hr.employees.show', $employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('access', null));
});
