<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Leave\LeaveRequest;
use Modules\HR\Domain\Leave\LeaveStatus;
use Modules\HR\Domain\Leave\LeaveType;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Persetujuan cuti berjenjang (PRD-001 §13.4, HR-3c).
 *
 * "Hari ini" Kamis 22 Okt 2026. Akun `guru` = pegawai di MA Unit 1. Tahap lembaga: `kepala.unit1`
 * (Kepala Lembaga Unit 1, cakupan memuat MA Unit 1). Tahap SDM: `admin.yayasan` (hr.leave.manage).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->guru = DevAccounts::user('guru');
    $this->kepala = DevAccounts::user('kepala.unit1');
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->annual = LeaveType::query()->withoutGlobalScope('tenant')->where('code', 'CUTI-TAHUNAN')->sole();

    pcuEmployee($this, $this->guru, 'Guru');
});

function pcuEmployee(mixed $test, User $user, string $name): void
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');
    $membership = Membership::query()->where('tenant_id', $test->tenant->id)->where('person_id', $user->person_id)->sole();

    app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membership->id, name: $name, gender: Gender::Male,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, 'U1-MA')->id,
        positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));
}

/** Guru mengajukan Cuti tahunan 26–30 Okt 2026 (5 hari kerja). */
function pcuGuruSubmits(mixed $test): LeaveRequest
{
    $test->flushSession();
    DevAccounts::chooseWorkspace($test, $test->guru, 'U1-MA');

    $test->actingAs($test->guru)->post(route('hr.my-leave.store'), [
        'leave_type_id' => $test->annual->id, 'start_date' => '2026-10-26', 'end_date' => '2026-10-30', 'reason' => 'Acara keluarga',
    ])->assertSessionHasNoErrors();

    $test->flushSession();

    return LeaveRequest::query()->withoutGlobalScope('tenant')->sole();
}

function pcuDecide(mixed $test, User $user, LeaveRequest $leave, string $decision, ?string $note = null): TestResponse
{
    return $test->actingAs($user)->post(route('hr.leave-approvals.decide', ['leave' => $leave->id]), array_filter(['decision' => $decision, 'note' => $note]));
}

test('dua tahap: Kepala Lembaga lalu Admin SDM; saldo terpakai; keputusan & audit tercatat; tidak bisa diputuskan dua kali', function () {
    $leave = pcuGuruSubmits($this);

    $this->actingAs($this->kepala)
        ->get(route('hr.leave-approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/leave-approvals')
            ->has('requests', 1)
            ->where('requests.0.id', $leave->id)
            ->where('requests.0.employee', 'Guru MA & MDA')
            ->where('requests.0.days', 5)
            ->where('requests.0.step', 1)
            ->where('requests.0.steps', 2)
            ->where('requests.0.quota', ['remaining' => 12, 'pending_other' => 0])
            ->where('requests.0.lembaga_skipped', false),
        );

    pcuDecide($this, $this->kepala, $leave, 'setuju')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('hr.leave-approvals.index'));

    expect($leave->refresh()->status)->toBe(LeaveStatus::Pending)
        ->and($leave->current_step)->toBe(2);

    $this->actingAs($this->kepala)->get(route('hr.leave-approvals.index'))
        ->assertInertia(fn (Assert $page) => $page->where('requests', []));
    $this->flushSession();

    $this->actingAs($this->admin)->get(route('hr.leave-approvals.index'))
        ->assertInertia(fn (Assert $page) => $page->has('requests', 1)->where('requests.0.step', 2));

    pcuDecide($this, $this->admin, $leave, 'setuju', 'Selamat berlibur')->assertSessionHasNoErrors();

    expect($leave->refresh()->status)->toBe(LeaveStatus::Approved)
        ->and($leave->current_step)->toBeNull()
        ->and($leave->decided_at)->not->toBeNull()
        ->and(DB::table('hr_leave_decisions')->where('leave_request_id', $leave->id)->orderBy('step')->pluck('decision')->all())->toBe([LeaveApprovals::APPROVED, LeaveApprovals::APPROVED]);

    $audits = DB::table('audit_logs')->where('action', HrAuditAction::LEAVE_APPROVED)->pluck('changes')->map(fn ($changes): array => json_decode((string) $changes, true));
    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('tahap')->sort()->values()->all())->toBe(['SDM', 'lembaga']);
    $final = $audits->firstWhere('tahap', 'SDM');
    expect([$final['tahap'], $final['final'], $final['hari'], $final['catatan_diisi']])->toBe(['SDM', true, 5, true])
        ->and(json_encode($final))->not->toContain('Selamat berlibur');

    pcuDecide($this, $this->admin, $leave, 'setuju')
        ->assertSessionHasErrors(['form' => 'Pengajuan ini sudah diputuskan. Muat ulang halaman.']);

    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('balances.0.used', 5)
            ->where('balances.0.pending', 0)
            ->where('balances.0.remaining', 7)
            ->where('requests.0.status', LeaveStatus::Approved->value),
        );
});

test('tolak wajib beralasan; alasannya terbaca di Cuti saya, tidak masuk audit', function () {
    $leave = pcuGuruSubmits($this);

    pcuDecide($this, $this->kepala, $leave, 'tolak')->assertSessionHasErrors(['note' => 'Tulis alasan penolakan.']);
    expect($leave->refresh()->status)->toBe(LeaveStatus::Pending);

    pcuDecide($this, $this->kepala, $leave, 'tolak', 'Bertepatan dengan ujian akhir semester')->assertSessionHasNoErrors();

    expect($leave->refresh()->status)->toBe(LeaveStatus::Rejected)
        ->and($leave->current_step)->toBeNull();

    $audit = json_decode((string) DB::table('audit_logs')->where('action', HrAuditAction::LEAVE_REJECTED)->sole()->changes, true);
    expect([$audit['tahap'], $audit['final'], $audit['catatan_diisi']])->toBe(['lembaga', true, true])
        ->and(json_encode($audit))->not->toContain('ujian');

    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.0.status', LeaveStatus::Rejected->value)
            ->where('requests.0.note', 'Bertepatan dengan ujian akhir semester')
            ->where('balances.0.pending', 0)
            ->where('balances.0.remaining', 12),
        );
});

test('di luar cakupan 404, tanpa izin 403; alur 1 tahap per jenjang; jatah dicek ulang saat persetujuan terakhir', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.leave-approval-flow.assign'), ['target' => 'jenjang', 'jenjang' => 'MA', 'steps' => '1'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)
        ->post(route('hr.leave-approval-flow.assign'), ['target' => 'jenjang', 'jenjang' => 'MA', 'steps' => '3'])
        ->assertSessionHasErrors('steps');

    $this->actingAs($this->admin)->get(route('hr.leave-settings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('approval.rules.0.target', 'Semua MA')
            ->where('approval.rules.0.days', '1 tahap (Kepala Lembaga)'),
        );
    $this->flushSession();

    $leave = pcuGuruSubmits($this);
    expect([$leave->approval_steps, $leave->current_step])->toBe([1, 1]);

    // Kepala SMK: punya izin, tapi MA Unit 1 di luar cakupannya.
    $smk = DevAccounts::user('kepala.smk');
    $this->actingAs($smk)->get(route('hr.leave-approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests', []));
    pcuDecide($this, $smk, $leave, 'setuju')->assertNotFound();
    $this->flushSession();

    // Guru tidak punya izin persetujuan.
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.leave-approvals.index'))->assertForbidden();
    $this->flushSession();

    // Jatah diturunkan setelah pengajuan masuk → persetujuan terakhir ditahan.
    $this->annual->forceFill(['annual_quota' => 3])->save();
    pcuDecide($this, $this->kepala, $leave, 'setuju')
        ->assertSessionHasErrors(['form' => 'Sisa jatah Cuti tahunan tahun 2026 tinggal 3 hari, kurang dari 5 hari yang diajukan. Tolak pengajuan ini atau minta Admin SDM menambah jatah.']);
    expect($leave->refresh()->status)->toBe(LeaveStatus::Pending);

    $this->annual->forceFill(['annual_quota' => 12])->save();
    pcuDecide($this, $this->kepala, $leave, 'setuju')->assertSessionHasNoErrors();

    expect($leave->refresh()->status)->toBe(LeaveStatus::Approved)
        ->and(DB::table('hr_leave_decisions')->where('leave_request_id', $leave->id)->count())->toBe(1);
});

test('Kepala Lembaga mengajukan cuti sendiri: tahap lembaga dilewati, langsung ke Admin SDM; tidak bisa memutuskan cutinya sendiri', function () {
    pcuEmployee($this, $this->kepala, 'Kepala');

    $this->actingAs($this->kepala)->post(route('hr.my-leave.store'), [
        'leave_type_id' => $this->annual->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'reason' => 'Urusan keluarga',
    ])->assertSessionHasNoErrors();

    $leave = LeaveRequest::query()->withoutGlobalScope('tenant')->sole();
    expect([$leave->approval_steps, $leave->current_step])->toBe([2, 2])
        ->and((array) DB::table('hr_leave_decisions')->where('leave_request_id', $leave->id)->sole())
        ->toMatchArray(['step' => 1, 'decision' => LeaveApprovals::SKIPPED, 'decided_by_membership_id' => null]);

    $requested = json_decode((string) DB::table('audit_logs')->where('action', HrAuditAction::LEAVE_REQUESTED)->sole()->changes, true);
    expect($requested['tahap_lembaga_dilewati'])->toBeTrue();

    $this->actingAs($this->kepala)->get(route('hr.leave-approvals.index'))
        ->assertInertia(fn (Assert $page) => $page->where('requests', []));
    pcuDecide($this, $this->kepala, $leave, 'setuju')->assertNotFound();
    expect($leave->refresh()->status)->toBe(LeaveStatus::Pending);
    $this->flushSession();

    $this->actingAs($this->admin)->get(route('hr.leave-approvals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('requests', 1)
            ->where('requests.0.employee', 'Kepala Unit 1')
            ->where('requests.0.lembaga_skipped', true),
        );

    pcuDecide($this, $this->admin, $leave, 'setuju')->assertSessionHasNoErrors();
    expect($leave->refresh()->status)->toBe(LeaveStatus::Approved);
});
