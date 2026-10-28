<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Student\Student;
use Modules\Academic\Domain\Student\StudentGuardian;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Application\Person\PersonNik;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * NIK siswa & orang tua/wali (AC-1g-a, PRD-002 §4.5, OD-AC-02 revisi 2026-10-23): opsional,
 * terenkripsi di Core, NIK sama = orang yang sama, tidak pernah masuk audit.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->mda = CoreFixtures::node($this->tenant, 'U3-MDA');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function snkIsian(string $organizationId, array $overrides = []): array
{
    return array_merge([
        'name' => 'Ahmad Fauzi',
        'gender' => 'L',
        'organization_id' => $organizationId,
        'start_date' => '2026-07-13',
        'entry_type' => 'BARU',
    ], $overrides);
}

function snkMembership(Tenant $tenant, string $name): Membership
{
    return Membership::query()
        ->withoutGlobalScope('tenant')
        ->where('tenant_id', $tenant->id)
        ->whereHas('person', fn ($person) => $person->where('name', $name))
        ->sole();
}

function snkStudent(Tenant $tenant, string $name): Student
{
    return Student::query()->withoutGlobalScope('tenant')->where('membership_id', snkMembership($tenant, $name)->id)->sole();
}

function snkNikOf(Tenant $tenant, string $name): ?string
{
    return app(PersonNik::class)->valueOf(snkMembership($tenant, $name)->person_id);
}

/** Orang di yayasan lain yang sudah punya NIK. */
function snkOrangLuar(string $nik): Person
{
    $person = Person::query()->create(['name' => 'Orang Yayasan Lain']);
    app(GrantMembership::class)->handle($person->id, CoreFixtures::tenant('YYS-B')->id);
    app(PersonNik::class)->assign($person->id, $nik);

    return $person;
}

// ── Siswa ─────────────────────────────────────────────────────────────────────

test('NIK siswa opsional; bila diisi tersimpan terenkripsi, tersamar di detail, lengkap di form ubah, tanpa nilai di audit', function () {
    $this->actingAs($this->operator)->post(route('academic.students.store'), snkIsian($this->smp->id, ['name' => 'Tanpa NIK']))->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), snkIsian($this->smp->id, ['nik' => '3201-0112-0413']))
        ->assertSessionHasErrors(['nik' => 'NIK harus 16 angka.']);

    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), snkIsian($this->smp->id, ['nik' => '3201 0112 0413 0001', 'birth_date' => '2013-04-12']))
        ->assertSessionHasNoErrors();

    $ahmad = snkStudent($this->tenant, 'Ahmad Fauzi');

    expect(snkNikOf($this->tenant, 'Ahmad Fauzi'))->toBe('3201011204130001')
        ->and(snkNikOf($this->tenant, 'Tanpa NIK'))->toBeNull();

    $audit = DB::table('audit_logs')->where('action', AcademicAuditAction::STUDENT_CREATED)->pluck('changes')->implode(' ');

    expect($audit)->toContain('nik')->and($audit)->not->toContain('3201011204130001');

    $this->actingAs($this->operator)
        ->get(route('academic.students.show', ['student' => $ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('student.personal.nik', '3201 •••• •••• 0001')
            ->where('student.personal.nik_warning', null)
            ->where('manage.profile.nik', '3201011204130001'));
});

test('NIK yang sudah milik anggota yayasan memunculkan orangnya sebagai calon, walau namanya beda dan operator memilih "Orang berbeda"', function () {
    $this->actingAs($this->admin)
        ->post(route('academic.students.store'), snkIsian($this->mda->id, ['nik' => '3201011204130001', 'birth_date' => '2013-04-12']))
        ->assertSessionHasNoErrors();
    $santri = snkMembership($this->tenant, 'Ahmad Fauzi');
    $persons = Person::query()->count();

    foreach ([[], ['confirm_new' => '1']] as $extra) {
        $this->actingAs($this->operator)
            ->post(route('academic.students.store'), snkIsian($this->smp->id, ['name' => 'Akhmad Fauzy', 'nik' => '3201011204130001'] + $extra))
            ->assertSessionHasErrors(['nik' => 'NIK ini sudah tercatat untuk Ahmad Fauzi (lihat kotak peringatan). Bila orangnya sama, pilih orang tersebut; bila berbeda, periksa lagi NIK-nya.']);

        $this->actingAs($this->operator)
            ->get(route('academic.students.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates', 1)
                ->where('candidates.0.membership_id', $santri->id)
                ->where('candidates.0.label', 'Ahmad Fauzi — siswa MDA Unit 3, lahir 2013-04-12 (NIK sama)'));
    }

    // Memilih orang tersebut: hanya pendaftaran SMP yang bertambah.
    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), snkIsian($this->smp->id, ['name' => 'Akhmad Fauzy', 'nik' => '3201011204130001', 'existing_membership_id' => $santri->id]))
        ->assertSessionHasNoErrors();

    expect(Person::query()->count())->toBe($persons)
        ->and(DB::table('ac_enrollments')->count())->toBe(2);
});

test('NIK siswa milik orang di yayasan lain ditolak tanpa menyebut namanya; NIK yang berbeda dari milik orang yang dipilih ditolak', function () {
    snkOrangLuar('3201011204130009');

    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), snkIsian($this->smp->id, ['nik' => '3201011204130009']))
        ->assertSessionHasErrors(['nik' => 'NIK ini sudah terdaftar di EduCore atas nama orang lain. Periksa kembali; bila NIK benar, hubungi admin platform.']);

    $this->actingAs($this->admin)
        ->post(route('academic.students.store'), snkIsian($this->mda->id, ['nik' => '3201011204130001']))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), snkIsian($this->smp->id, [
            'nik' => '3201011204130002',
            'existing_membership_id' => snkMembership($this->tenant, 'Ahmad Fauzi')->id,
        ]))
        ->assertSessionHasErrors(['nik' => 'NIK berbeda dengan yang sudah tercatat untuk orang ini. Kosongkan bila tidak ingin mengubahnya.']);
});

test('orang lama ber-NIK dengan nama sama tetapi tanggal lahir beda bukan calon orang ganda', function () {
    $this->actingAs($this->admin)
        ->post(route('academic.students.store'), snkIsian($this->mda->id, ['nik' => '3201011204130001', 'birth_date' => '2013-04-12']))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), snkIsian($this->smp->id, ['nik' => '3201010105140002', 'birth_date' => '2014-05-01']))
        ->assertSessionHasNoErrors();

    expect(Person::query()->where('name', 'Ahmad Fauzi')->count())->toBe(2);
});

test('ubah data diri siswa: NIK bisa dilengkapi & diperbaiki, tidak bisa dikosongkan, dan peringatan bila tidak cocok', function () {
    $this->actingAs($this->operator)->post(route('academic.students.store'), snkIsian($this->smp->id, ['birth_date' => '2013-04-12']))->assertSessionHasNoErrors();
    $ahmad = snkStudent($this->tenant, 'Ahmad Fauzi');
    $url = route('academic.students.update', ['student' => $ahmad->id]);
    $form = fn (string $nik): array => ['name' => 'Ahmad Fauzi', 'gender' => 'L', 'birth_date' => '2013-04-12', 'nik' => $nik];

    $this->actingAs($this->operator)->patch($url, $form('3201011204130001'))->assertSessionHasNoErrors();
    $this->actingAs($this->operator)->patch($url, $form(''))
        ->assertSessionHasErrors(['nik' => 'NIK yang sudah tercatat tidak bisa dikosongkan. Perbaiki angkanya bila salah.']);

    // Tanggal di NIK 13-04, bukan 12-04: tersimpan, tetapi detail memberi peringatan.
    $this->actingAs($this->operator)->patch($url, $form('3201011304130001'))->assertSessionHasNoErrors();

    expect(snkNikOf($this->tenant, 'Ahmad Fauzi'))->toBe('3201011304130001')
        ->and(app(PersonNik::class)->ownerOf('3201011204130001'))->toBeNull();

    $this->actingAs($this->operator)
        ->get(route('academic.students.show', ['student' => $ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page->where('student.personal.nik_warning', 'NIK tidak cocok dengan tanggal lahir. Periksa kembali bila perlu.'));

    $audit = DB::table('audit_logs')->where('action', AcademicAuditAction::STUDENT_UPDATED)->pluck('changes')->implode(' ');

    expect($audit)->toContain('nik')->and($audit)->not->toContain('3201011304130001');
});

// ── Orang tua/wali ────────────────────────────────────────────────────────────

test('orang tua dengan NIK yang sudah tercatat dipakai ulang walau nama/HP berbeda', function () {
    $this->actingAs($this->admin)->post(route('academic.students.store'), snkIsian($this->smp->id, [
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222', 'nik' => '3201015505800003']],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();
    $persons = Person::query()->count();

    $this->actingAs($this->admin)->post(route('academic.students.store'), snkIsian($this->mda->id, [
        'name' => 'Aisyah Fauzi',
        'gender' => 'P',
        'guardians' => [['relation' => 'IBU', 'name' => 'Ibu Siti', 'phone' => '085700000000', 'nik' => '3201 0155 0580 0003']],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();

    $ibu = snkMembership($this->tenant, 'Siti Aminah');

    // Hanya Aisyah yang orang baru; nama & HP ibu tidak ditimpa.
    expect(Person::query()->count())->toBe($persons + 1)
        ->and(StudentGuardian::query()->withoutGlobalScope('tenant')->where('guardian_membership_id', $ibu->id)->count())->toBe(2)
        ->and(Person::query()->whereKey($ibu->person_id)->value('phone'))->toBe('081311112222');
});

test('orang tua dengan nama & HP sama: tanpa NIK dipakai ulang dan NIK dilengkapi; ber-NIK lain dengan HP yang sama ditolak (HP unik)', function () {
    $this->actingAs($this->admin)->post(route('academic.students.store'), snkIsian($this->smp->id, [
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222']],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('academic.students.store'), snkIsian($this->mda->id, [
        'name' => 'Aisyah Fauzi',
        'gender' => 'P',
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222', 'nik' => '3201015505800003']],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();

    expect(Person::query()->where('name', 'Siti Aminah')->count())->toBe(1)
        ->and(snkNikOf($this->tenant, 'Siti Aminah'))->toBe('3201015505800003');

    $this->actingAs($this->admin)->post(route('academic.students.store'), snkIsian($this->mda->id, [
        'name' => 'Umar Fauzi',
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222', 'nik' => '3201015505800004']],
        'primary_guardian' => 0,
    ]))->assertSessionHasErrors('guardians.0.phone');

    // Orang lain bernama sama (NIK lain) dengan HP sendiri: orang baru.
    $this->actingAs($this->admin)->post(route('academic.students.store'), snkIsian($this->mda->id, [
        'name' => 'Umar Fauzi',
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311119999', 'nik' => '3201015505800004']],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();

    expect(Person::query()->where('name', 'Siti Aminah')->count())->toBe(2);
});

test('NIK orang tua milik orang di yayasan lain ditolak di isiannya; tidak ada yang tersimpan', function () {
    snkOrangLuar('3201015505800009');
    $persons = Person::query()->count();

    $this->actingAs($this->admin)->post(route('academic.students.store'), snkIsian($this->smp->id, [
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'nik' => '3201015505800009']],
        'primary_guardian' => 0,
    ]))->assertSessionHasErrors(['guardians.0.nik' => 'NIK ini sudah terdaftar di EduCore atas nama orang lain. Periksa kembali; bila NIK benar, hubungi admin platform.']);

    expect(Person::query()->count())->toBe($persons)
        ->and(DB::table('ac_students')->count())->toBe(0);
});

test('detail siswa: tambah orang tua ber-NIK, NIK tersamar di daftar, ubah NIK (kosong = tetap; milik orang lain ditolak)', function () {
    $this->actingAs($this->admin)->post(route('academic.students.store'), snkIsian($this->smp->id, [
        'guardians' => [['relation' => 'AYAH', 'name' => 'Budi Santoso', 'nik' => '3201010101800001']],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();
    $ahmad = snkStudent($this->tenant, 'Ahmad Fauzi');

    $this->actingAs($this->operator)
        ->post(route('academic.guardians.store', ['student' => $ahmad->id]), [
            'mode' => 'baru', 'relation' => 'IBU', 'name' => 'Siti Aminah', 'nik' => '3201015505800003',
        ])->assertSessionHasNoErrors();

    $link = StudentGuardian::query()->withoutGlobalScope('tenant')
        ->where('student_id', $ahmad->id)
        ->where('guardian_membership_id', snkMembership($this->tenant, 'Siti Aminah')->id)
        ->sole();
    $update = route('academic.guardians.update', ['student' => $ahmad->id, 'guardian' => $link->id]);

    $this->actingAs($this->operator)
        ->get(route('academic.students.show', ['student' => $ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('student.guardians', fn ($guardians) => collect($guardians)->pluck('nik')->sort()->values()->all() === ['3201 •••• •••• 0001', '3201 •••• •••• 0003']));

    // Kosong = NIK tidak berubah.
    $this->actingAs($this->operator)->patch($update, ['relation' => 'IBU', 'name' => 'Siti Aminah', 'nik' => ''])->assertSessionHasNoErrors();
    expect(snkNikOf($this->tenant, 'Siti Aminah'))->toBe('3201015505800003');

    $this->actingAs($this->operator)->patch($update, ['relation' => 'IBU', 'name' => 'Siti Aminah', 'nik' => '3201010101800001'])
        ->assertSessionHasErrors(['nik' => 'NIK ini sudah tercatat atas nama Budi Santoso di yayasan ini.']);

    $this->actingAs($this->operator)->patch($update, ['relation' => 'IBU', 'name' => 'Siti Aminah', 'nik' => '3201015505800005'])->assertSessionHasNoErrors();

    expect(snkNikOf($this->tenant, 'Siti Aminah'))->toBe('3201015505800005');

    $audit = DB::table('audit_logs')->where('action', AcademicAuditAction::GUARDIAN_UPDATED)->pluck('changes')->implode(' ');

    expect($audit)->toContain('nik')->and($audit)->not->toContain('3201015505800005');
});
