<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Kelas/rombel & wali kelas (AC-1d-a, PRD-002 §4.2, §4.4, §6, §7).
 *
 * Data uji:
 *   Kalender Yayasan: tahun berjalan (seeder), tahun lalu, tahun depan
 *   Pegawai: Ani (guru SMP Unit 3), Budi (guru MTs Unit 1)
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;
    $this->mts = CoreFixtures::node($this->tenant, 'U1-MTS')->id;

    $this->current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $first = (int) substr($this->current->name, 0, 4);
    $this->pastYear = klsYear($this->current, ($first - 1).'/'.$first, ($first - 1).'-07-01', $first.'-06-30');
    $this->nextYear = klsYear($this->current, ($first + 1).'/'.($first + 2), ($first + 1).'-07-01', ($first + 2).'-06-30');

    $this->ani = klsEmployee($this->tenant, 'Ani Guru', 'U3-SMP');
    $this->budi = klsEmployee($this->tenant, 'Budi Guru', 'U1-MTS');
});

/** Tahun ajaran tambahan di kalender yang sama dengan tahun berjalan. */
function klsYear(AcademicYear $current, string $name, string $start, string $end): AcademicYear
{
    $year = new AcademicYear;
    $year->forceFill([
        'tenant_id' => $current->tenant_id,
        'calendar_id' => $current->calendar_id,
        'name' => $name,
        'start_date' => $start,
        'end_date' => $end,
        'is_current' => false,
    ])->save();

    return $year;
}

function klsEmployee(Tenant $tenant, string $name, string $organizationCode): Employee
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $tenant->id,
        membershipId: null,
        name: $name,
        gender: Gender::Female,
        birthPlace: null,
        birthDate: null,
        phone: null,
        address: null,
        employeeNumber: null,
        nuptk: null,
        educationLevel: null,
        educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null,
        startDate: '2020-07-01',
        organizationId: CoreFixtures::node($tenant, $organizationCode)->id,
        positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function klsIsian(string $organizationId, string $yearId, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $organizationId,
        'academic_year_id' => $yearId,
        'grade' => 7,
        'name' => 'VII-A',
        'homeroom_employee_id' => '',
    ], $overrides);
}

function klsClass(string $name): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->sole();
}

// ── Halaman ───────────────────────────────────────────────────────────────────

test('Admin Yayasan membuka Kelas: tahun berjalan terpilih, pilihan wali kelas hanya pegawai aktif lembaga itu', function () {
    $this->actingAs($this->admin)
        ->get(route('academic.classes.index', ['lembaga' => $this->smp]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/classes/index')
            ->where('filters.lembaga', $this->smp)
            ->where('filters.tahun', $this->current->id)
            ->has('options.years', 3)
            ->where('options.years.0.value', $this->nextYear->id)
            ->where('year.current', true)
            ->where('year.past', false)
            ->where('canManage', true)
            ->has('classes', 0)
            ->where('homerooms', [['id' => $this->ani->id, 'name' => 'Ani Guru']]),
        );
});

test('operator hanya melihat lembaganya; Kepala Unit 1 melihat tanpa hak kelola; tamu & tanpa izin ditolak', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.classes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('options.organizations', [['value' => $this->smp, 'label' => 'SMP Unit 3']])
            ->where('filters.lembaga', $this->smp)
            ->where('canManage', true),
        );

    // Lembaga di luar cakupan lewat URL diabaikan (kembali ke pilihan pertama).
    $this->actingAs($this->operator)
        ->get(route('academic.classes.index', ['lembaga' => $this->mts]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.lembaga', $this->smp));

    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('academic.classes.index', ['lembaga' => $this->mts]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.lembaga', $this->mts)
            ->where('canManage', false)
            ->where('homerooms', []),
        );

    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('academic.classes.index'))->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->get(route('academic.classes.index'))->assertRedirect(route('login'));
});

// ── Tambah ────────────────────────────────────────────────────────────────────

test('operator menambah kelas dengan wali kelas; tercatat di audit dan tampil di daftar', function () {
    $this->actingAs($this->operator)
        ->post(route('academic.classes.store'), klsIsian($this->smp, $this->current->id, [
            'name' => '  VII   A ', 'homeroom_employee_id' => $this->ani->id,
        ]))->assertSessionHasNoErrors();

    $class = klsClass('VII A');

    expect($class->grade)->toBe(7)
        ->and($class->homeroom_employee_id)->toBe($this->ani->id)
        ->and($class->is_active)->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_CREATED)->where('subject_id', $class->id)->exists())->toBeTrue();

    $this->actingAs($this->operator)
        ->get(route('academic.classes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('classes.0.name', 'VII A')
            ->where('classes.0.homeroom.name', 'Ani Guru')
            ->where('classes.0.homeroom.active', true)
            ->where('classes.0.members', ['total' => 0, 'male' => 0, 'female' => 0])
            ->where('classes.0.has_history', false),
        );

    // Tahun depan boleh disiapkan.
    $this->actingAs($this->operator)
        ->post(route('academic.classes.store'), klsIsian($this->smp, $this->nextYear->id))
        ->assertSessionHasNoErrors();
});

test('tambah kelas: nama dobel, wali kelas dari lembaga lain, tahun lalu, tahun kalender lain, dan isian salah ditolak', function () {
    $store = route('academic.classes.store');

    $this->actingAs($this->operator)->post($store, klsIsian($this->smp, $this->current->id))->assertSessionHasNoErrors();

    $this->actingAs($this->operator)->post($store, klsIsian($this->smp, $this->current->id, ['name' => 'vii-a']))
        ->assertSessionHasErrors(['name' => 'Nama kelas vii-a sudah dipakai di lembaga ini pada tahun ajaran yang sama.']);

    $this->actingAs($this->operator)->post($store, klsIsian($this->smp, $this->current->id, ['name' => 'VII-B', 'homeroom_employee_id' => $this->budi->id]))
        ->assertSessionHasErrors(['homeroom_employee_id' => 'Wali kelas harus pegawai aktif yang ditempatkan di lembaga ini.']);

    $this->actingAs($this->operator)->post($store, klsIsian($this->smp, $this->pastYear->id, ['name' => 'VII-C']))
        ->assertSessionHasErrors(['form']);

    // Tahun ajaran dari kalender yang tidak dipakai SMP Unit 3.
    $otherCalendar = (string) Str::uuid7();
    DB::table('ac_academic_calendars')->insert([
        'id' => $otherCalendar, 'tenant_id' => $this->tenant->id, 'name' => 'Kalender MDA', 'is_default' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherYear = new AcademicYear;
    $otherYear->forceFill([
        'tenant_id' => $this->tenant->id, 'calendar_id' => $otherCalendar, 'name' => $this->current->name,
        'start_date' => $this->current->start_date, 'end_date' => $this->current->end_date, 'is_current' => true,
    ])->save();

    $this->actingAs($this->operator)->post($store, klsIsian($this->smp, $otherYear->id, ['name' => 'VII-D']))
        ->assertSessionHasErrors(['academic_year_id']);

    $this->actingAs($this->operator)->post($store, klsIsian($this->smp, $this->current->id, ['name' => '', 'grade' => 13, 'homeroom_employee_id' => 'bukan-uuid']))
        ->assertSessionHasErrors(['name', 'grade', 'homeroom_employee_id']);

    expect(SchoolClass::query()->withoutGlobalScope('tenant')->count())->toBe(1);
});

test('tambah kelas: lembaga di luar cakupan 404, tanpa izin kelola 403', function () {
    $this->actingAs($this->operator)
        ->post(route('academic.classes.store'), klsIsian($this->mts, $this->current->id))
        ->assertNotFound();

    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('academic.classes.store'), klsIsian($this->mts, $this->current->id))
        ->assertForbidden();

    expect(SchoolClass::query()->withoutGlobalScope('tenant')->count())->toBe(0);
});

// ── Ubah, nonaktifkan, hapus ──────────────────────────────────────────────────

test('ubah kelas; wali kelas yang sudah tidak aktif ditandai dan boleh tetap tercatat sampai diganti', function () {
    $this->actingAs($this->operator)
        ->post(route('academic.classes.store'), klsIsian($this->smp, $this->current->id, ['homeroom_employee_id' => $this->ani->id]))
        ->assertSessionHasNoErrors();
    $class = klsClass('VII-A');

    // Ani berhenti.
    DB::table('hr_employees')->where('id', $this->ani->id)->update(['status' => 'BERHENTI']);

    $this->actingAs($this->operator)
        ->get(route('academic.classes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('classes.0.homeroom.active', false)
            ->where('homerooms', []),
        );

    $this->actingAs($this->operator)
        ->patch(route('academic.classes.update', ['class' => $class->id]), ['grade' => 8, 'name' => 'VIII-A', 'homeroom_employee_id' => $this->ani->id])
        ->assertSessionHasNoErrors();

    expect($class->fresh()?->name)->toBe('VIII-A')
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_UPDATED)->sole()->changes, true)['field_diubah'])
        ->toEqualCanonicalizing(['grade', 'name']);

    // Mengganti ke pegawai yang tidak aktif di lembaga ini ditolak; mengosongkan boleh.
    $this->actingAs($this->operator)
        ->patch(route('academic.classes.update', ['class' => $class->id]), ['grade' => 8, 'name' => 'VIII-A', 'homeroom_employee_id' => $this->budi->id])
        ->assertSessionHasErrors(['homeroom_employee_id']);
    $this->actingAs($this->operator)
        ->patch(route('academic.classes.update', ['class' => $class->id]), ['grade' => 8, 'name' => 'VIII-A', 'homeroom_employee_id' => ''])
        ->assertSessionHasNoErrors();

    expect($class->fresh()?->homeroom_employee_id)->toBeNull();
});

test('nonaktifkan & aktifkan kembali; kelas kosong boleh dihapus, yang pernah punya anggota tidak', function () {
    $this->actingAs($this->operator)->post(route('academic.classes.store'), klsIsian($this->smp, $this->current->id))->assertSessionHasNoErrors();
    $this->actingAs($this->operator)->post(route('academic.classes.store'), klsIsian($this->smp, $this->current->id, ['name' => 'VII-B']))->assertSessionHasNoErrors();
    $a = klsClass('VII-A');
    $b = klsClass('VII-B');

    $this->actingAs($this->operator)->post(route('academic.classes.status', ['class' => $a->id]), ['active' => '0'])->assertSessionHasNoErrors();
    expect($a->fresh()?->is_active)->toBeFalse();
    $this->actingAs($this->operator)->post(route('academic.classes.status', ['class' => $a->id]), ['active' => '1'])->assertSessionHasNoErrors();
    expect($a->fresh()?->is_active)->toBeTrue();

    // VII-B pernah punya anggota.
    $this->actingAs($this->admin)->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => $this->smp, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();
    $enrollment = DB::table('ac_enrollments')->where('organization_id', $this->smp)->value('id');
    DB::table('ac_class_members')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => $this->smp,
        'academic_year_id' => $this->current->id, 'class_id' => $b->id, 'enrollment_id' => $enrollment,
        'start_date' => '2025-07-14', 'end_date' => '2025-08-01', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->operator)->delete(route('academic.classes.destroy', ['class' => $b->id]))
        ->assertSessionHasErrors(['form' => 'Kelas ini sudah pernah punya anggota, jadi tidak bisa dihapus. Nonaktifkan saja.']);
    $this->actingAs($this->operator)->delete(route('academic.classes.destroy', ['class' => $a->id]))->assertSessionHasNoErrors();

    expect(SchoolClass::query()->withoutGlobalScope('tenant')->pluck('name')->all())->toBe(['VII-B'])
        ->and(DB::table('audit_logs')->whereIn('action', [
            AcademicAuditAction::CLASS_DEACTIVATED, AcademicAuditAction::CLASS_REACTIVATED, AcademicAuditAction::CLASS_DELETED,
        ])->count())->toBe(3);
});

test('kelas tahun ajaran yang sudah lewat hanya bisa dilihat', function () {
    $class = new SchoolClass;
    $class->forceFill([
        'tenant_id' => $this->tenant->id, 'organization_id' => $this->smp, 'academic_year_id' => $this->pastYear->id,
        'grade' => 7, 'name' => 'VII-Lama',
    ])->save();

    $this->actingAs($this->operator)
        ->get(route('academic.classes.index', ['tahun' => $this->pastYear->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('year.past', true)
            ->where('canManage', false)
            ->where('classes.0.name', 'VII-Lama'),
        );

    $this->actingAs($this->operator)->patch(route('academic.classes.update', ['class' => $class->id]), ['grade' => 7, 'name' => 'Baru'])
        ->assertSessionHasErrors(['form' => 'Tahun ajaran ini sudah lewat. Kelasnya hanya bisa dilihat, tidak bisa diubah.']);
    $this->actingAs($this->operator)->post(route('academic.classes.status', ['class' => $class->id]), ['active' => '0'])
        ->assertSessionHasErrors(['form']);
    $this->actingAs($this->operator)->delete(route('academic.classes.destroy', ['class' => $class->id]))
        ->assertSessionHasErrors(['form']);

    expect($class->fresh()?->name)->toBe('VII-Lama');
});

test('kelas lembaga lain atau ID asal-asalan: 404; tanpa izin kelola: 403', function () {
    $this->actingAs($this->admin)->post(route('academic.classes.store'), klsIsian($this->mts, $this->current->id, ['name' => 'VII-MTs']))->assertSessionHasNoErrors();
    $mtsClass = klsClass('VII-MTs');

    $this->actingAs($this->operator)->patch(route('academic.classes.update', ['class' => $mtsClass->id]), ['grade' => 7, 'name' => 'X'])->assertNotFound();
    $this->actingAs($this->operator)->delete(route('academic.classes.destroy', ['class' => (string) Str::uuid7()]))->assertNotFound();
    $this->actingAs(DevAccounts::user('kepala.unit1'))->post(route('academic.classes.status', ['class' => $mtsClass->id]), ['active' => '0'])->assertForbidden();

    expect($mtsClass->fresh()?->is_active)->toBeTrue();
});
