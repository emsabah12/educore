<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Leave\LeaveRequest;
use Modules\HR\Domain\Leave\LeaveStatus;
use Modules\HR\Domain\Leave\LeaveType;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Cuti saya — layanan mandiri (PRD-001 §13.3, HR-3b).
 *
 * "Hari ini" Kamis 22 Okt 2026. Akun `guru` tertaut ke pegawai dengan penempatan utama MA Unit 1;
 * kalender kerja bawaan (libur Ahad, tanpa tanggal merah); jatah Cuti tahunan 12 hari.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->guru = DevAccounts::user('guru');

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $membership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole();
    $this->employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id, membershipId: $membership->id, name: 'Guru', gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($this->tenant, 'U1-MA')->id,
        positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));

    $this->annual = LeaveType::query()->withoutGlobalScope('tenant')->where('code', 'CUTI-TAHUNAN')->sole();

    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
});

/**
 * @param  array<string, string>  $overrides
 */
function ctySubmit(mixed $test, array $overrides = []): TestResponse
{
    return $test->actingAs($test->guru)->post(route('hr.my-leave.store'), array_merge([
        'leave_type_id' => $test->annual->id, 'start_date' => '2026-10-26', 'end_date' => '2026-10-30', 'reason' => 'Acara keluarga',
    ], $overrides));
}

test('halaman Cuti saya: saldo, jenis, riwayat; pratinjau jumlah hari kerja dari kalender kerja', function () {
    $this->actingAs($this->guru)
        ->get(route('hr.my-leave.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/my-leave')
            ->where('linked', true)
            ->where('hr.isEmployee', true)
            ->where('year', 2026)
            ->where('balances', [[
                'type_id' => $this->annual->id, 'name' => 'Cuti tahunan', 'quota' => 12, 'adjustment' => 0, 'used' => 0, 'pending' => 0, 'remaining' => 12,
            ]])
            ->has('types', 4)
            ->where('requests', []),
        );

    // Senin–Jumat = 5 hari; Ahad 1 Nov saja = libur.
    $this->actingAs($this->guru)->getJson(route('hr.my-leave.preview', ['mulai' => '2026-10-26', 'selesai' => '2026-11-01']))
        ->assertOk()
        ->assertJson(['days' => 6, 'days_by_year' => ['2026' => 6]]);
    $this->actingAs($this->guru)->getJson(route('hr.my-leave.preview', ['mulai' => '2026-11-01', 'selesai' => '2026-11-01']))
        ->assertStatus(422)
        ->assertJson(['message' => 'Tidak ada hari kerja pada rentang tanggal ini (semuanya libur).']);
});

test('ajukan: menunggu tahap 1 dari 2, saldo menunggu bertambah; tumpang tindih, lebih dari sisa jatah, terlalu mundur ditolak; tercatat di audit tanpa alasan', function () {
    ctySubmit($this)->assertSessionHasNoErrors()->assertRedirect(route('hr.my-leave.index'));

    $leave = LeaveRequest::query()->withoutGlobalScope('tenant')->sole();

    expect($leave->status)->toBe(LeaveStatus::Pending)
        ->and([$leave->days, $leave->approval_steps, $leave->current_step])->toBe([5, 2, 1])
        ->and($leave->organization_id)->toBe(CoreFixtures::node($this->tenant, 'U1-MA')->id);

    $audit = json_decode((string) DB::table('audit_logs')->where('action', HrAuditAction::LEAVE_REQUESTED)->sole()->changes, true);
    expect([$audit['jenis'], $audit['mulai'], $audit['selesai'], $audit['hari'], $audit['tahap']])->toBe(['Cuti tahunan', '2026-10-26', '2026-10-30', 5, 2])
        ->and(json_encode($audit))->not->toContain('Acara keluarga');

    ctySubmit($this, ['start_date' => '2026-10-29', 'end_date' => '2026-11-03'])
        ->assertSessionHasErrors(['start_date' => 'Tanggalnya bertumpang dengan pengajuan lain (2026-10-26 s.d. 2026-10-30, Menunggu).']);

    // 2–13 Nov = 11 hari kerja; sisa 12 − 5 menunggu = 7.
    ctySubmit($this, ['start_date' => '2026-11-02', 'end_date' => '2026-11-13'])
        ->assertSessionHasErrors(['end_date' => 'Sisa jatah Cuti tahunan tahun 2026 tinggal 7 hari (sudah dikurangi pengajuan yang masih menunggu); pengajuan ini 11 hari.']);

    ctySubmit($this, ['start_date' => '2026-09-01', 'end_date' => '2026-09-01'])
        ->assertSessionHasErrors(['start_date' => 'Tanggal mulai paling lama 30 hari sebelum hari ini.']);
    ctySubmit($this, ['reason' => ''])->assertSessionHasErrors(['reason' => 'Tulis alasan cuti.']);

    $this->actingAs($this->guru)
        ->get(route('hr.my-leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('balances.0.pending', 5)
            ->where('balances.0.remaining', 12)
            ->where('requests.0.status_label', 'Menunggu tahap 1 dari 2')
            ->where('requests.0.reason', 'Acara keluarga'),
        );

    expect(LeaveRequest::query()->withoutGlobalScope('tenant')->count())->toBe(1);
});

test('tarik pengajuan yang menunggu; pengajuan lintas tahun dibagi per tahun; akun tanpa data pegawai tidak bisa mengajukan', function () {
    ctySubmit($this)->assertSessionHasNoErrors();
    $leave = LeaveRequest::query()->withoutGlobalScope('tenant')->sole();

    $this->actingAs($this->guru)->post(route('hr.my-leave.withdraw', ['leave' => $leave->id]))->assertSessionHasNoErrors();

    expect($leave->refresh()->status)->toBe(LeaveStatus::Withdrawn)
        ->and($leave->current_step)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::LEAVE_WITHDRAWN)->count())->toBe(1);

    $this->actingAs($this->guru)->post(route('hr.my-leave.withdraw', ['leave' => $leave->id]))
        ->assertSessionHasErrors(['form' => 'Pengajuan ini sudah diputuskan, jadi tidak bisa ditarik.']);

    // 28–31 Des 2026 (Senin–Kamis) = 4 hari; 1–4 Jan 2027 tanpa Ahad 3 Jan = 3 hari.
    ctySubmit($this, ['start_date' => '2026-12-28', 'end_date' => '2027-01-04'])->assertSessionHasNoErrors();
    $crossYear = LeaveRequest::query()->withoutGlobalScope('tenant')->where('status', LeaveStatus::Pending->value)->sole();

    expect($crossYear->days)->toBe(7)
        ->and($crossYear->days_by_year)->toEqual([2026 => 4, 2027 => 3]);

    $this->flushSession();
    $admin = DevAccounts::user('admin.yayasan');

    $this->actingAs($admin)
        ->get(route('hr.my-leave.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('linked', false)->where('hr.isEmployee', false));
    $this->actingAs($admin)->post(route('hr.my-leave.store'), ['leave_type_id' => $this->annual->id, 'start_date' => '2026-10-26', 'end_date' => '2026-10-26', 'reason' => 'x'])
        ->assertNotFound();
    $this->actingAs($admin)->post(route('hr.my-leave.withdraw', ['leave' => $crossYear->id]))->assertNotFound();
});
