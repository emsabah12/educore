<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Subject\Subject;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Mata pelajaran per lembaga (AC-3a, PRD-002 §16).
 *
 * Data uji: Matematika (MTK, tingkat 7–9) dan Fiqih (semua tingkat) di SMP Unit 3; Matematika di MTs Unit 1.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;
    $this->mts = CoreFixtures::node($this->tenant, 'U1-MTS')->id;

    foreach ([
        [$this->smp, ['name' => 'Matematika', 'code' => 'MTK', 'grade_min' => '7', 'grade_max' => '9']],
        [$this->smp, ['name' => 'Fiqih']],
        [$this->mts, ['name' => 'Matematika', 'code' => 'MTK']],
    ] as [$organization, $values]) {
        $this->actingAs($this->admin)
            ->post(route('academic.subjects.store'), array_merge(['organization_id' => $organization], $values))
            ->assertSessionHasNoErrors();
    }
});

function mplSubject(string $organizationId, string $name): Subject
{
    return Subject::query()->withoutGlobalScope('tenant')->where('organization_id', $organizationId)->where('name', $name)->sole();
}

// ── Daftar ────────────────────────────────────────────────────────────────────

test('daftar: per lembaga yang terlihat, urut nama; kelola hanya untuk pengelola kelas', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.subjects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/subjects/index')
            ->where('filters.lembaga', $this->smp)
            ->where('canManage', true)
            ->has('subjects', 2)
            ->where('subjects.0.name', 'Fiqih')
            ->where('subjects.0.grades', 'Semua tingkat')
            ->where('subjects.1.code', 'MTK')
            ->where('subjects.1.grades', 'Tingkat 7–9'),
        );

    // Lembaga di luar cakupan di URL diabaikan (kembali ke bawaan).
    $this->actingAs($this->operator)
        ->get(route('academic.subjects.index', ['lembaga' => $this->mts]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.lembaga', $this->smp));

    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('academic.subjects.index', ['lembaga' => $this->mts]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.lembaga', $this->mts)->where('canManage', false)->has('subjects', 1));

    // Guru punya dua penugasan: pilih lembaga kerja dulu, baru ditolak karena tanpa izin lihat siswa.
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('academic.subjects.index'))->assertForbidden();
});

// ── Tambah ────────────────────────────────────────────────────────────────────

test('tambah: nama dirapikan, kode huruf besar tanpa spasi, rentang tingkat; tercatat di audit', function () {
    $this->actingAs($this->operator)
        ->post(route('academic.subjects.store'), [
            'organization_id' => $this->smp, 'name' => '  Bahasa   Arab ', 'code' => ' b arab ', 'grade_min' => '8', 'grade_max' => '8',
        ])
        ->assertSessionHasNoErrors();

    $subject = mplSubject($this->smp, 'Bahasa Arab');

    expect($subject->code)->toBe('BARAB')
        ->and($subject->grade_min)->toBe(8)
        ->and($subject->grade_max)->toBe(8)
        ->and($subject->is_active)->toBeTrue()
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::SUBJECT_CREATED)->where('subject_id', $subject->id)->sole()->changes, true))
        ->toEqual(['nama' => 'Bahasa Arab', 'kode' => 'BARAB', 'lembaga' => 'U3-SMP', 'tingkat' => 'Tingkat 8']);
});

test('tambah ditolak: nama/kode sudah ada di lembaga itu, tingkat setengah diisi, tingkat terbalik', function (array $values, string $field, string $message) {
    $this->actingAs($this->operator)
        ->post(route('academic.subjects.store'), array_merge(['organization_id' => $this->smp], $values))
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'nama sama (huruf beda)' => [['name' => 'MATEMATIKA'], 'name', 'Mata pelajaran MATEMATIKA sudah ada di lembaga ini.'],
    'kode sama' => [['name' => 'Matematika Lanjut', 'code' => 'mtk'], 'code', 'Kode MTK sudah dipakai mata pelajaran lain di lembaga ini.'],
    'tingkat setengah' => [['name' => 'IPA', 'grade_min' => '7'], 'grade_min', 'Isi tingkat dari dan sampai, atau kosongkan keduanya untuk semua tingkat.'],
    'tingkat terbalik' => [['name' => 'IPA', 'grade_min' => '9', 'grade_max' => '7'], 'grade_max', 'Tingkat sampai tidak boleh lebih kecil dari tingkat dari.'],
    'nama kosong' => [['name' => '   '], 'name', 'Nama mata pelajaran wajib diisi.'],
]);

// ── Ubah, nonaktifkan, hapus ──────────────────────────────────────────────────

test('ubah: nama & tingkat berubah; nama milik mapel lain ditolak; tanpa perubahan tidak dicatat', function () {
    $subject = mplSubject($this->smp, 'Fiqih');

    $this->actingAs($this->operator)
        ->patch(route('academic.subjects.update', ['subject' => $subject->id]), ['name' => 'matematika'])
        ->assertSessionHasErrors(['name' => 'Mata pelajaran matematika sudah ada di lembaga ini.']);

    $this->actingAs($this->operator)
        ->patch(route('academic.subjects.update', ['subject' => $subject->id]), ['name' => 'Fiqih Ibadah', 'code' => 'FQH', 'grade_min' => '7', 'grade_max' => '8'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->patch(route('academic.subjects.update', ['subject' => $subject->id]), ['name' => 'Fiqih Ibadah', 'code' => 'fqh', 'grade_min' => '7', 'grade_max' => '8'])
        ->assertSessionHasNoErrors();

    $subject->refresh();

    expect($subject->name)->toBe('Fiqih Ibadah')
        ->and($subject->code)->toBe('FQH')
        ->and($subject->grade_max)->toBe(8)
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::SUBJECT_UPDATED)->count())->toBe(1);
});

test('nonaktifkan, aktifkan kembali, dan hapus tercatat di audit', function () {
    $subject = mplSubject($this->smp, 'Fiqih');

    $this->actingAs($this->operator)->post(route('academic.subjects.status', ['subject' => $subject->id]), ['active' => '0'])->assertSessionHasNoErrors();
    expect($subject->refresh()->is_active)->toBeFalse();

    $this->actingAs($this->operator)->post(route('academic.subjects.status', ['subject' => $subject->id]), ['active' => '1'])->assertSessionHasNoErrors();
    expect($subject->refresh()->is_active)->toBeTrue();

    $this->actingAs($this->operator)->delete(route('academic.subjects.destroy', ['subject' => $subject->id]))->assertSessionHasNoErrors();

    expect(Subject::query()->withoutGlobalScope('tenant')->whereKey($subject->id)->exists())->toBeFalse();

    foreach ([AcademicAuditAction::SUBJECT_DEACTIVATED, AcademicAuditAction::SUBJECT_REACTIVATED, AcademicAuditAction::SUBJECT_DELETED] as $action) {
        expect(DB::table('audit_logs')->where('action', $action)->where('subject_id', $subject->id)->count())->toBe(1);
    }
});

test('hak akses: lembaga di luar cakupan 404, tanpa izin kelola 403', function () {
    $mtsSubject = mplSubject($this->mts, 'Matematika');
    $kepala = DevAccounts::user('kepala.unit1');

    $this->actingAs($this->operator)->post(route('academic.subjects.store'), ['organization_id' => $this->mts, 'name' => 'IPA'])->assertNotFound();
    $this->actingAs($kepala)->post(route('academic.subjects.store'), ['organization_id' => $this->mts, 'name' => 'IPA'])->assertForbidden();

    $this->actingAs($this->operator)->patch(route('academic.subjects.update', ['subject' => $mtsSubject->id]), ['name' => 'X'])->assertNotFound();
    $this->actingAs($kepala)->patch(route('academic.subjects.update', ['subject' => $mtsSubject->id]), ['name' => 'X'])->assertForbidden();
    $this->actingAs($kepala)->delete(route('academic.subjects.destroy', ['subject' => $mtsSubject->id]))->assertForbidden();
    $this->actingAs($this->operator)->delete(route('academic.subjects.destroy', ['subject' => (string) Str::uuid7()]))->assertNotFound();

    expect($mtsSubject->refresh()->name)->toBe('Matematika')
        ->and(Subject::query()->withoutGlobalScope('tenant')->where('name', 'IPA')->exists())->toBeFalse();
});
