<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Student\Student;
use Modules\Academic\Domain\Student\StudentGuardian;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Contracts\EmployeeLookup;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Orang tua/wali di detail siswa (AC-1c-b2, PRD-002 §4.5, §6): cari lewat HP, tambah, ubah, lepas,
 * kontak utama.
 *
 * Data uji (semua di SMP Unit 3 kecuali Budi):
 *   Ahmad  — ibu Siti Aminah (HP 081311112222, kontak utama); HP Ahmad sendiri 081200001111
 *   Aisyah — adik Ahmad, ibu yang sama (dipakai ulang)
 *   Citra  — belum punya orang tua/wali
 *   Budi   — MTs Unit 1
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;
    $ibu = ['guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222']], 'primary_guardian' => 0];

    $this->actingAs($this->admin)->post(route('academic.students.store'), walIsian('Ahmad Fauzi', $smp, $ibu + ['phone' => '081200001111']))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.students.store'), walIsian('Aisyah Fauzi', $smp, $ibu + ['gender' => 'P']))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.students.store'), walIsian('Citra Lestari', $smp, ['gender' => 'P']))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.students.store'), walIsian('Budi Santoso', CoreFixtures::node($this->tenant, 'U1-MTS')->id))->assertSessionHasNoErrors();

    $this->ahmad = walStudent($this->tenant, 'Ahmad Fauzi');
    $this->aisyah = walStudent($this->tenant, 'Aisyah Fauzi');
    $this->citra = walStudent($this->tenant, 'Citra Lestari');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function walIsian(string $name, string $organizationId, array $overrides = []): array
{
    return array_merge([
        'name' => $name,
        'gender' => 'L',
        'organization_id' => $organizationId,
        'start_date' => '2025-07-14',
        'entry_type' => 'BARU',
    ], $overrides);
}

function walMembership(Tenant $tenant, string $name): Membership
{
    return Membership::query()
        ->where('tenant_id', $tenant->id)
        ->whereHas('person', fn ($person) => $person->where('name', $name))
        ->sole();
}

function walStudent(Tenant $tenant, string $name): Student
{
    return Student::query()->withoutGlobalScope('tenant')->where('membership_id', walMembership($tenant, $name)->id)->sole();
}

/** Tautan orang tua/wali bernama $name pada siswa $student. */
function walLink(Tenant $tenant, Student $student, string $name): StudentGuardian
{
    return StudentGuardian::query()
        ->withoutGlobalScope('tenant')
        ->where('student_id', $student->id)
        ->where('guardian_membership_id', walMembership($tenant, $name)->id)
        ->sole();
}

/**
 * @return list<string> nama orang tua/wali siswa, kontak utama ditandai "*"
 */
function walNames(Student $student): array
{
    return DB::table('ac_student_guardians as g')
        ->join('memberships as m', 'm.id', '=', 'g.guardian_membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('g.student_id', $student->id)
        ->orderBy('p.name')
        ->get(['p.name', 'g.is_primary_contact'])
        ->map(fn ($row): string => $row->name.($row->is_primary_contact ? '*' : ''))
        ->all();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function walBaru(string $name, array $overrides = []): array
{
    return array_merge(['mode' => 'baru', 'relation' => 'AYAH', 'name' => $name], $overrides);
}

// ── Detail & pencarian ────────────────────────────────────────────────────────

test('detail memuat tautan orang tua/wali untuk form; jumlah siswa lain tanpa nama', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.students.show', ['student' => $this->ahmad->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manage.guardians', true)
            ->where('manage.maxGuardians', 4)
            ->has('manage.options.relations', 4)
            ->where('student.guardians.0.id', walLink($this->tenant, $this->ahmad, 'Siti Aminah')->id)
            ->where('student.guardians.0.relation_code', 'IBU')
            ->where('student.guardians.0.other_students', 1)
            ->where('student.guardians.0.locked', false),
        );
});

test('cari lewat HP: hanya orang tua/wali yang sudah tercatat, nomor harus cocok persis, tanpa nama anak', function () {
    $url = fn (Student $student, string $hp) => route('academic.guardians.search', ['student' => $student->id, 'hp' => $hp]);

    $this->actingAs($this->operator)->getJson($url($this->citra, '0813-1111-2222'))
        ->assertOk()
        ->assertExactJson([
            'phone' => '081311112222',
            'results' => [[
                'membership_id' => walMembership($this->tenant, 'Siti Aminah')->id,
                'name' => 'Siti Aminah',
                'already_linked' => false,
            ]],
        ]);

    $this->actingAs($this->operator)->getJson($url($this->ahmad, '081311112222'))
        ->assertJsonPath('results.0.already_linked', true);

    // Sebagian nomor, atau nomor siswa (bukan orang tua/wali): tidak ada hasil.
    $this->actingAs($this->operator)->getJson($url($this->citra, '08131111'))->assertJsonPath('results', []);
    $this->actingAs($this->operator)->getJson($url($this->citra, '081200001111'))->assertJsonPath('results', []);

    $this->actingAs($this->operator)->getJson($url($this->citra, 'abc'))
        ->assertStatus(422)
        ->assertJsonPath('results', []);
});

// ── Tambah ────────────────────────────────────────────────────────────────────

test('tambah dari pencarian: orang yang sama tertaut, otomatis kontak utama bila belum ada', function () {
    $persons = Person::query()->count();

    $this->actingAs($this->operator)
        ->post(route('academic.guardians.store', ['student' => $this->citra->id]), [
            'mode' => 'ada',
            'membership_id' => walMembership($this->tenant, 'Siti Aminah')->id,
            'phone' => '081311112222',
            'relation' => 'WALI',
        ])->assertSessionHasNoErrors();

    expect(Person::query()->count())->toBe($persons)
        ->and(walNames($this->citra))->toBe(['Siti Aminah*'])
        ->and(walLink($this->tenant, $this->citra, 'Siti Aminah')->relation->value)->toBe('WALI');

    // Sudah tertaut.
    $this->actingAs($this->operator)
        ->post(route('academic.guardians.store', ['student' => $this->citra->id]), [
            'mode' => 'ada',
            'membership_id' => walMembership($this->tenant, 'Siti Aminah')->id,
            'phone' => '081311112222',
            'relation' => 'IBU',
        ])->assertSessionHasErrors(['form' => 'Orang ini sudah tercatat sebagai orang tua/wali siswa ini.']);
});

test('tambah dari pencarian: ID orang yang diubah di browser atau HP yang tidak cocok ditolak', function () {
    $store = route('academic.guardians.store', ['student' => $this->citra->id]);

    // Ahmad anggota yayasan, tetapi bukan orang tua/wali.
    $this->actingAs($this->operator)->post($store, [
        'mode' => 'ada', 'membership_id' => walMembership($this->tenant, 'Ahmad Fauzi')->id, 'phone' => '081200001111', 'relation' => 'WALI',
    ])->assertSessionHasErrors(['membership_id']);

    $this->actingAs($this->operator)->post($store, [
        'mode' => 'ada', 'membership_id' => walMembership($this->tenant, 'Siti Aminah')->id, 'phone' => '081399998888', 'relation' => 'IBU',
    ])->assertSessionHasErrors(['membership_id']);

    $this->actingAs($this->operator)->post($store, ['mode' => 'ada', 'relation' => 'IBU'])
        ->assertSessionHasErrors(['membership_id', 'phone']);

    expect(walNames($this->citra))->toBe([]);
});

test('tambah orang baru: nama + HP sama dipakai ulang, kontak utama bisa dipindah, maksimal 4', function () {
    $store = route('academic.guardians.store', ['student' => $this->citra->id]);

    $this->actingAs($this->operator)->post($store, walBaru('  siti   aminah ', ['relation' => 'IBU', 'phone' => '0813 1111 2222']))
        ->assertSessionHasNoErrors();
    expect(Person::query()->where('name', 'Siti Aminah')->count())->toBe(1);

    $this->actingAs($this->operator)->post($store, walBaru('Hasan Lestari', ['gender' => 'L', 'phone' => '081277776666', 'make_primary' => '1']))
        ->assertSessionHasNoErrors();
    expect(walNames($this->citra))->toBe(['Hasan Lestari*', 'Siti Aminah'])
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::GUARDIAN_LINKED)->where('subject_id', $this->citra->id)->count())->toBe(2);

    $this->actingAs($this->operator)->post($store, walBaru('Paman Satu', ['relation' => 'LAINNYA']))->assertSessionHasNoErrors();
    $this->actingAs($this->operator)->post($store, walBaru('Paman Dua', ['relation' => 'LAINNYA']))->assertSessionHasNoErrors();
    $this->actingAs($this->operator)->post($store, walBaru('Paman Tiga', ['relation' => 'LAINNYA']))
        ->assertSessionHasErrors(['form' => 'Paling banyak 4 orang tua/wali. Lepas salah satu dulu.']);

    expect(Person::query()->where('name', 'Paman Tiga')->exists())->toBeFalse();

    $this->actingAs($this->operator)->post($store, walBaru('', ['relation' => 'TIDAK-ADA', 'phone' => 'abc']))
        ->assertSessionHasErrors(['name', 'relation', 'phone']);
});

// ── Ubah ──────────────────────────────────────────────────────────────────────

test('ubah orang tua/wali: data diri ikut berubah untuk kakak-adik; audit tanpa nilai', function () {
    $link = walLink($this->tenant, $this->ahmad, 'Siti Aminah');

    $this->actingAs($this->operator)
        ->patch(route('academic.guardians.update', ['student' => $this->ahmad->id, 'guardian' => $link->id]), [
            'relation' => 'WALI', 'name' => 'Siti Aminah Rahmawati', 'gender' => 'P', 'phone' => '0813-5555-4444',
        ])->assertSessionHasNoErrors();

    $person = Person::query()->whereKey(walMembership($this->tenant, 'Siti Aminah Rahmawati')->person_id)->sole();
    $changes = json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::GUARDIAN_UPDATED)->sole()->changes, true);

    expect($person->phone)->toBe('081355554444')
        ->and($link->fresh()?->relation->value)->toBe('WALI')
        ->and(walNames($this->aisyah))->toBe(['Siti Aminah Rahmawati*'])
        ->and($changes['field_diubah'])->toEqualCanonicalizing(['relation', 'name', 'gender', 'phone'])
        ->and(json_encode($changes))->not->toContain('5555');

    $this->actingAs($this->operator)
        ->patch(route('academic.guardians.update', ['student' => $this->ahmad->id, 'guardian' => $link->id]), [
            'relation' => 'IBU', 'name' => '', 'phone' => 'abc',
        ])->assertSessionHasErrors(['phone']);
});

test('orang tua/wali yang juga pegawai atau siswa: hanya hubungannya yang bisa diubah', function () {
    $sitiMembership = walMembership($this->tenant, 'Siti Aminah')->id;

    // Siti juga pegawai (dibaca lewat kontrak HR).
    $this->app->instance(EmployeeLookup::class, new class($sitiMembership) implements EmployeeLookup
    {
        public function __construct(private readonly string $employeeMembershipId) {}

        public function employeeMembershipIds(string $tenantId, array $membershipIds): array
        {
            return in_array($this->employeeMembershipId, $membershipIds, true) ? [$this->employeeMembershipId] : [];
        }

        public function employeeIdForMembership(string $tenantId, string $membershipId): ?string
        {
            return null;
        }
    });

    $link = walLink($this->tenant, $this->ahmad, 'Siti Aminah');

    $this->actingAs($this->operator)
        ->get(route('academic.students.show', ['student' => $this->ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page->where('student.guardians.0.locked', true));

    $this->actingAs($this->operator)
        ->patch(route('academic.guardians.update', ['student' => $this->ahmad->id, 'guardian' => $link->id]), [
            'relation' => 'WALI', 'name' => 'Nama Diganti', 'phone' => '089999999999',
        ])->assertSessionHasNoErrors();

    $person = Person::query()->whereKey(walMembership($this->tenant, 'Siti Aminah')->person_id)->sole();

    expect($person->phone)->toBe('081311112222')
        ->and($link->fresh()?->relation->value)->toBe('WALI');

    // Kakak yang menjadi wali adiknya juga terkunci (punya profil siswa).
    $this->actingAs($this->operator)->post(route('academic.guardians.store', ['student' => $this->citra->id]), [
        'mode' => 'baru', 'relation' => 'WALI', 'name' => 'Ahmad Fauzi', 'phone' => '081200001111',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->get(route('academic.students.show', ['student' => $this->citra->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('student.guardians.0.name', 'Ahmad Fauzi')
            ->where('student.guardians.0.locked', true),
        );
});

// ── Lepas & kontak utama ──────────────────────────────────────────────────────

test('lepas kontak utama wajib memilih pengganti bila masih ada orang tua/wali lain; orangnya tetap ada', function () {
    $this->actingAs($this->operator)
        ->post(route('academic.guardians.store', ['student' => $this->ahmad->id]), walBaru('Hasan Fauzi'))
        ->assertSessionHasNoErrors();

    $siti = walLink($this->tenant, $this->ahmad, 'Siti Aminah');
    $hasan = walLink($this->tenant, $this->ahmad, 'Hasan Fauzi');
    $destroy = fn (StudentGuardian $link) => route('academic.guardians.destroy', ['student' => $this->ahmad->id, 'guardian' => $link->id]);

    $this->actingAs($this->operator)->delete($destroy($siti))
        ->assertSessionHasErrors(['new_primary_id' => 'Orang ini kontak utama. Pilih orang tua/wali lain sebagai kontak utama pengganti.']);
    $this->actingAs($this->operator)->delete($destroy($siti), ['new_primary_id' => $siti->id])
        ->assertSessionHasErrors(['new_primary_id']);

    $this->actingAs($this->operator)->delete($destroy($siti), ['new_primary_id' => $hasan->id])->assertSessionHasNoErrors();

    expect(walNames($this->ahmad))->toBe(['Hasan Fauzi*'])
        ->and(walNames($this->aisyah))->toBe(['Siti Aminah*'])
        ->and(Person::query()->where('name', 'Siti Aminah')->exists())->toBeTrue();

    // Orang tua/wali terakhir boleh dilepas tanpa pengganti.
    $this->actingAs($this->operator)->delete($destroy($hasan))->assertSessionHasNoErrors();

    expect(walNames($this->ahmad))->toBe([])
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::GUARDIAN_UNLINKED)->count())->toBe(2);
});

test('ganti kontak utama memindah tanda; tautan milik siswa lain ditolak', function () {
    $this->actingAs($this->operator)
        ->post(route('academic.guardians.store', ['student' => $this->ahmad->id]), walBaru('Hasan Fauzi'))
        ->assertSessionHasNoErrors();

    $hasan = walLink($this->tenant, $this->ahmad, 'Hasan Fauzi');

    $this->actingAs($this->operator)
        ->post(route('academic.guardians.primary', ['student' => $this->ahmad->id, 'guardian' => $hasan->id]))
        ->assertSessionHasNoErrors();

    expect(walNames($this->ahmad))->toBe(['Hasan Fauzi*', 'Siti Aminah'])
        ->and(walNames($this->aisyah))->toBe(['Siti Aminah*'])
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::PRIMARY_CONTACT_CHANGED)->count())->toBe(1);

    // Tautan Aisyah dipakai di alamat Ahmad.
    $this->actingAs($this->operator)
        ->post(route('academic.guardians.primary', ['student' => $this->ahmad->id, 'guardian' => walLink($this->tenant, $this->aisyah, 'Siti Aminah')->id]))
        ->assertSessionHasErrors(['form' => 'Orang tua/wali ini tidak tercatat untuk siswa ini.']);
});

// ── Hak akses ─────────────────────────────────────────────────────────────────

test('tanpa izin kelola 403, siswa di luar cakupan 404, termasuk pencarian HP', function () {
    $budi = walStudent($this->tenant, 'Budi Santoso');
    $kepala = DevAccounts::user('kepala.unit1');
    $koordinator = DevAccounts::user('koordinator.mda');

    $this->actingAs($kepala)->getJson(route('academic.guardians.search', ['student' => $budi->id, 'hp' => '081311112222']))->assertForbidden();
    $this->actingAs($kepala)->post(route('academic.guardians.store', ['student' => $budi->id]), walBaru('Ayah Budi'))->assertForbidden();
    $this->actingAs($this->operator)->getJson(route('academic.guardians.search', ['student' => $budi->id, 'hp' => '081311112222']))->assertNotFound();
    $this->actingAs($this->operator)->post(route('academic.guardians.store', ['student' => $budi->id]), walBaru('Ayah Budi'))->assertNotFound();
    $this->actingAs($this->admin)->post(route('academic.guardians.store', ['student' => (string) Str::uuid7()]), walBaru('Ayah'))->assertNotFound();

    // Koordinator MDA tidak melihat siswa SMP sama sekali.
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);
    $this->actingAs($koordinator)
        ->delete(route('academic.guardians.destroy', ['student' => $this->ahmad->id, 'guardian' => walLink($this->tenant, $this->ahmad, 'Siti Aminah')->id]))
        ->assertNotFound();

    expect(Person::query()->where('name', 'like', 'Ayah%')->exists())->toBeFalse()
        ->and(walNames($this->ahmad))->toBe(['Siti Aminah*']);
});
