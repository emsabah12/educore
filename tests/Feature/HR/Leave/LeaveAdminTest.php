<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Leave\LeaveApprovals;
use Modules\HR\Application\Leave\ManageLeaveRequests;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Leave\LeaveRequest;
use Modules\HR\Domain\Leave\LeaveStatus;
use Modules\HR\Domain\Leave\LeaveType;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Kelola cuti — Admin SDM (PRD-001 §13.5, HR-3d-a): daftar & saringan, detail, catat atas nama,
 * batalkan.
 *
 * "Hari ini" Kamis 22 Okt 2026. Akun `guru` = pegawai di MA Unit 1; `admin.yayasan` = Admin SDM.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->guru = DevAccounts::user('guru');
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->annual = LeaveType::query()->withoutGlobalScope('tenant')->where('code', 'CUTI-TAHUNAN')->sole();
    $this->sick = LeaveType::query()->withoutGlobalScope('tenant')->where('code', 'SAKIT')->sole();

    $this->employee = klcEmployee($this, $this->guru, 'Guru');
});

function klcMembership(mixed $test, User $user): Membership
{
    return Membership::query()->where('tenant_id', $test->tenant->id)->where('person_id', $user->person_id)->sole();
}

function klcEmployee(mixed $test, User $user, string $name): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: klcMembership($test, $user)->id, name: $name, gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, 'U1-MA')->id,
        positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

/**
 * @param  array<string, string>  $overrides
 */
function klcRecord(mixed $test, array $overrides = []): TestResponse
{
    return $test->actingAs($test->admin)->post(route('hr.leaves.store'), array_merge([
        'employee_id' => $test->employee, 'leave_type_id' => $test->annual->id,
        'start_date' => '2026-11-02', 'end_date' => '2026-11-04', 'reason' => 'Melapor langsung ke kantor',
    ], $overrides));
}

test('catat atas nama: cari pegawai, pratinjau hari, langsung disetujui & memakai jatah; boleh mundur 90 hari; aturan tumpang tindih sama', function () {
    $this->actingAs($this->admin)->get(route('hr.leaves.create', ['cari' => 'g']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('hr/leaves/record')->where('candidates', [])->where('employee', null));

    $this->actingAs($this->admin)->get(route('hr.leaves.create', ['cari' => 'guru']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('candidates', 1)
            ->where('candidates.0.id', $this->employee)
            ->where('candidates.0.name', 'Guru MA & MDA')
            ->where('candidates.0.organization', CoreFixtures::node($this->tenant, 'U1-MA')->name),
        );

    $this->actingAs($this->admin)->get(route('hr.leaves.create', ['pegawai' => $this->employee]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.id', $this->employee)
            ->where('balances.0.remaining', 12)
            ->has('types', 4),
        );

    $this->actingAs($this->admin)->getJson(route('hr.leaves.preview', ['pegawai' => $this->employee, 'mulai' => '2026-11-02', 'selesai' => '2026-11-08']))
        ->assertOk()
        ->assertJson(['days' => 6]);

    $response = klcRecord($this)->assertSessionHasNoErrors();

    $leave = LeaveRequest::query()->withoutGlobalScope('tenant')->sole();
    $response->assertRedirect(route('hr.leaves.show', ['leave' => $leave->id]));

    expect($leave->status)->toBe(LeaveStatus::Approved)
        ->and([$leave->days, $leave->approval_steps, $leave->current_step])->toBe([3, 1, null])
        ->and($leave->recorded_by_membership_id)->toBe(klcMembership($this, $this->admin)->id)
        ->and((array) DB::table('hr_leave_decisions')->where('leave_request_id', $leave->id)->sole())
        ->toMatchArray(['step' => 1, 'decision' => LeaveApprovals::RECORDED, 'decided_by_membership_id' => klcMembership($this, $this->admin)->id]);

    $audit = json_decode((string) DB::table('audit_logs')->where('action', HrAuditAction::LEAVE_RECORDED)->sole()->changes, true);
    expect([$audit['jenis'], $audit['hari']])->toBe(['Cuti tahunan', 3])
        ->and(json_encode($audit))->not->toContain('Melapor')
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::LEAVE_REQUESTED)->count())->toBe(0);

    // Sakit yang dilaporkan belakangan: 60 hari lalu boleh, 100 hari lalu tidak.
    klcRecord($this, ['leave_type_id' => $this->sick->id, 'start_date' => '2026-08-24', 'end_date' => '2026-08-24'])->assertSessionHasNoErrors();
    klcRecord($this, ['leave_type_id' => $this->sick->id, 'start_date' => '2026-07-14', 'end_date' => '2026-07-14'])
        ->assertSessionHasErrors(['start_date' => 'Tanggal mulai paling lama 90 hari sebelum hari ini.']);
    klcRecord($this, ['start_date' => '2026-11-04', 'end_date' => '2026-11-05'])
        ->assertSessionHasErrors(['start_date' => 'Tanggalnya bertumpang dengan pengajuan lain (2026-11-02 s.d. 2026-11-04, Disetujui).']);
    klcRecord($this, ['employee_id' => (string) Str::uuid7()])
        ->assertSessionHasErrors(['form' => 'Pegawai ini tidak aktif atau belum punya penempatan utama.']);

    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-leave.index'))
        ->assertInertia(fn (Assert $page) => $page->where('balances.0.used', 3)->where('balances.0.remaining', 9));
});

test('daftar dengan saringan lembaga, status, bulan; detail dengan riwayat keputusan; hanya Admin SDM di tingkat Yayasan', function () {
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->post(route('hr.my-leave.store'), [
        'leave_type_id' => $this->annual->id, 'start_date' => '2026-10-26', 'end_date' => '2026-10-30', 'reason' => 'Acara keluarga',
    ])->assertSessionHasNoErrors();
    $this->flushSession();

    klcRecord($this, ['leave_type_id' => $this->sick->id])->assertSessionHasNoErrors();

    $index = fn (array $query = []) => $this->actingAs($this->admin)->get(route('hr.leaves.index', $query));

    $index()->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('hr/leaves/index')
        ->where('leaves.total', 2)
        ->where('leaves.data.0.start_date', '2026-11-02')
        ->where('leaves.data.0.recorded', true)
        ->where('leaves.data.1.status_label', 'Menunggu tahap lembaga (1 dari 2)')
        ->has('options.statuses', 5),
    );

    $index(['status' => 'MENUNGGU'])->assertInertia(fn (Assert $page) => $page->where('leaves.total', 1)->where('leaves.data.0.type', 'Cuti tahunan'));
    $index(['bulan' => '2026-11'])->assertInertia(fn (Assert $page) => $page->where('leaves.total', 1)->where('leaves.data.0.type', 'Sakit'));
    $index(['lembaga' => CoreFixtures::node($this->tenant, 'PONPES-U1')->id])->assertInertia(fn (Assert $page) => $page->where('leaves.total', 2));
    $index(['lembaga' => CoreFixtures::node($this->tenant, 'U3-SMK')->id])->assertInertia(fn (Assert $page) => $page->where('leaves.data', []));
    $index(['status' => 'ASAL', 'bulan' => '2026-13', 'lembaga' => 'x'])->assertInertia(fn (Assert $page) => $page
        ->where('filters', ['lembaga' => '', 'jenis' => '', 'status' => '', 'bulan' => ''])
        ->where('leaves.total', 2),
    );

    $pending = LeaveRequest::query()->withoutGlobalScope('tenant')->where('status', LeaveStatus::Pending->value)->sole();

    $this->actingAs($this->admin)->get(route('hr.leaves.show', ['leave' => $pending->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/leaves/show')
            ->where('leave.employee', 'Guru MA & MDA')
            ->where('leave.reason', 'Acara keluarga')
            ->where('leave.recorded_by', null)
            ->where('leave.decisions', [])
            ->where('canCancel', false)
            ->missing('leave.employee_membership_id'),
        );
    $this->actingAs($this->admin)->get(route('hr.leaves.show', ['leave' => (string) Str::uuid7()]))->assertNotFound();

    // Kepala Lembaga punya izin persetujuan, bukan kelola cuti.
    $kepala = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepala)->get(route('hr.leaves.index'))->assertForbidden();
    $this->actingAs($kepala)->get(route('hr.leaves.show', ['leave' => $pending->id]))->assertForbidden();
    $this->actingAs($kepala)->post(route('hr.leaves.store'), [])->assertForbidden();
});

test('batalkan cuti yang disetujui: alasan wajib, jatah kembali, alasan terbaca pegawai, tidak masuk audit', function () {
    klcRecord($this)->assertSessionHasNoErrors();
    $leave = LeaveRequest::query()->withoutGlobalScope('tenant')->sole();

    $this->actingAs($this->admin)->get(route('hr.leaves.show', ['leave' => $leave->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canCancel', true)
            ->where('leave.recorded_by', 'Admin Yayasan')
            ->where('leave.decisions.0.label', 'Dicatat Admin SDM atas nama pegawai'),
        );

    $this->actingAs($this->admin)->post(route('hr.leaves.cancel', ['leave' => $leave->id]), ['reason' => ''])
        ->assertSessionHasErrors(['reason' => 'Tulis alasan pembatalan.']);

    $this->actingAs($this->admin)->post(route('hr.leaves.cancel', ['leave' => $leave->id]), ['reason' => 'Tugas mendadak ke luar kota dibatalkan'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('hr.leaves.show', ['leave' => $leave->id]));

    expect($leave->refresh()->status)->toBe(LeaveStatus::Cancelled)
        ->and(DB::table('hr_leave_decisions')->where('leave_request_id', $leave->id)->where('decision', LeaveApprovals::CANCELLED)->value('note'))->toBe('Tugas mendadak ke luar kota dibatalkan');

    $audit = json_decode((string) DB::table('audit_logs')->where('action', HrAuditAction::LEAVE_CANCELLED)->sole()->changes, true);
    expect($audit['hari'])->toBe(3)->and(json_encode($audit))->not->toContain('luar kota');

    $this->actingAs($this->admin)->get(route('hr.leaves.show', ['leave' => $leave->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canCancel', false)
            ->where('leave.status', 'DIBATALKAN')
            ->where('leave.decisions.1.label', 'Dibatalkan'),
        );
    $this->actingAs($this->admin)->post(route('hr.leaves.cancel', ['leave' => $leave->id]), ['reason' => 'Lagi'])
        ->assertSessionHasErrors(['form' => 'Hanya cuti yang sudah disetujui yang bisa dibatalkan.']);

    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('balances.0.used', 0)
            ->where('balances.0.remaining', 12)
            ->where('requests.0.status', 'DIBATALKAN')
            ->where('requests.0.note', 'Tugas mendadak ke luar kota dibatalkan'),
        );
});

test('Admin SDM tidak mencatat atau membatalkan cutinya sendiri', function () {
    $own = klcEmployee($this, $this->admin, 'Admin');

    klcRecord($this, ['employee_id' => $own])
        ->assertSessionHasErrors(['form' => 'Anda tidak bisa mencatat cuti Anda sendiri. Ajukan lewat Cuti saya, atau minta Admin SDM lain.']);

    // Cuti admin yang dicatat orang lain.
    $leave = app(ManageLeaveRequests::class)->record($this->tenant->id, $own, $this->annual->id, '2026-11-02', '2026-11-02', 'Keperluan keluarga', klcMembership($this, $this->guru)->id);

    $this->actingAs($this->admin)->get(route('hr.leaves.show', ['leave' => $leave->id]))
        ->assertInertia(fn (Assert $page) => $page->where('own', true)->where('canCancel', false));
    $this->actingAs($this->admin)->post(route('hr.leaves.cancel', ['leave' => $leave->id]), ['reason' => 'Coba'])
        ->assertSessionHasErrors(['form' => 'Anda tidak bisa membatalkan cuti Anda sendiri. Ajukan lewat Cuti saya, atau minta Admin SDM lain.']);

    expect($leave->refresh()->status)->toBe(LeaveStatus::Approved);
});
