<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Contracts\DormitoryResidents;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Placement\Placement;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Application\Invoice\VoidDormitoryInvoicesAfterLeaving;
use Modules\Finance\Domain\Fee\FeeKind;
use Modules\Finance\Domain\Fee\FeeType;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * FN-6 Biaya asrama (PRD-003 §20, PRD-008 OD-DM-10). Hari ini 15 Nov 2026; tahun ajaran 2026/2027.
 *
 * SMP Unit 3 (di bawah Unit 3). Asrama "ASR-U3" milik Unit 3 (A-101: 6 bed, A-102: 2 bed) dan "ASR-SMP" milik SMP
 * Unit 3 sendiri (S-101: 2 bed). Santri SMP:
 *   Ahmad — A-101 sejak 15 Jul 2026
 *   Budi  — A-101 sejak 20 Okt 2026
 *   Eko   — A-101 15 Jul 2026, check-out 10 Sep 2026
 *   Fajar — A-102 sejak 15 Jul 2026
 *   Dodi  — tidak tinggal di asrama
 * Jenis biaya: MAKAN (bulanan, hanya penghuni asrama, Rp 500.000), SPP (bulanan biasa, Rp 150.000),
 * PANGKAL (sekali bayar, hanya penghuni asrama, Rp 1.000.000).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->actingAs(DevAccounts::user('admin.yayasan'));
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    $this->person = [];

    foreach (['Ahmad', 'Budi', 'Dodi', 'Eko', 'Fajar'] as $name) {
        $this->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();
        $this->person[$name] = DB::table('ac_students as s')->join('memberships as m', 'm.id', '=', 's.membership_id')->join('persons as p', 'p.id', '=', 'm.person_id')
            ->join('ac_enrollments as e', 'e.student_id', '=', 's.id')->where('p.name', $name)->first(['s.membership_id', 'e.id as enrollment_id']);
    }

    $this->room = [];

    foreach ([['ASR-U3', 'PONPES-U3', [['A-101', 6], ['A-102', 2]]], ['ASR-SMP', 'U3-SMP', [['S-101', 2]]]] as [$code, $owner, $rooms]) {
        $this->post(route('dormitory.dormitories.store'), ['organization_id' => CoreFixtures::node($this->tenant, $owner)->id, 'code' => $code, 'name' => "Asrama {$code}"])
            ->assertSessionHasNoErrors();
        $dormitoryId = Dormitory::query()->where('code', $code)->sole()->id;
        $this->post(route('dormitory.buildings.store', ['dormitory' => $dormitoryId]), ['code' => 'G'.substr($code, -3), 'name' => 'Gedung '.$code, 'gender' => 'L'])
            ->assertSessionHasNoErrors();
        $buildingId = (string) DB::table('dm_buildings')->where('dormitory_id', $dormitoryId)->value('id');

        foreach ($rooms as [$roomCode, $beds]) {
            $this->post(route('dormitory.rooms.store', ['building' => $buildingId]), ['code' => $roomCode, 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI'])
                ->assertSessionHasNoErrors();
            $room = Room::query()->where('code', $roomCode)->sole();
            $this->post(route('dormitory.resources.store', ['room' => $room->id]), ['kind' => 'bed', 'prefix' => $roomCode.'-', 'start' => 1, 'count' => $beds])
                ->assertSessionHasNoErrors();
            $this->room[$roomCode] = $room->id;
        }
    }

    foreach ([['Ahmad', 'A-101', '2026-07-15'], ['Budi', 'A-101', '2026-10-20'], ['Eko', 'A-101', '2026-07-15'], ['Fajar', 'A-102', '2026-07-15']] as [$name, $room, $date]) {
        $this->post(route('dormitory.placements.store', ['room' => $this->room[$room]]), [
            'membership_id' => $this->person[$name]->membership_id, 'category' => 'PENGHUNI', 'mode' => 'langsung', 'date' => $date,
        ])->assertSessionHasNoErrors();
    }

    fndCheckOut($this, 'Eko', '2026-09-10');

    $this->post(route('finance.fee-types.store'), ['code' => 'MAKAN', 'name' => 'Uang makan & asrama', 'kind' => 'BULANAN', 'dormitory_only' => '1'])->assertSessionHasNoErrors();
    $this->makan = FeeType::query()->where('code', 'MAKAN')->sole();
    $this->spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    $this->pangkal = app(ManageFeeTypes::class)->create($this->tenant->id, 'PANGKAL', 'Uang pangkal asrama', FeeKind::Once, dormitoryOnly: true);

    foreach ([[$this->makan, '500000'], [$this->spp, '150000'], [$this->pangkal, '1000000']] as [$type, $amount]) {
        app(ManageFeeRates::class)->set($this->tenant->id, $type->id, $this->smp->id, $this->year->id, null, $amount, null);
    }
});

function fndCheckOut(mixed $test, string $name, string $date): void
{
    $placement = Placement::query()->where('membership_id', $test->person[$name]->membership_id)->where('status', 'AKTIF')->sole();
    $test->post(route('dormitory.placements.check-out', ['placement' => $placement->id]), ['date' => $date, 'end_reason' => 'KELUAR_ASRAMA'])->assertSessionHasNoErrors();
}

/**
 * @return array<string, string>
 */
function fndMonthly(mixed $test, FeeType $type, string $from, string $to): array
{
    return ['organization_id' => $test->smp->id, 'academic_year_id' => $test->year->id, 'fee_type_id' => $type->id, 'from' => $from, 'to' => $to];
}

/**
 * @return list<string> "Nama Y-m status" untuk satu jenis biaya, urut nama lalu bulan
 */
function fndInvoices(FeeType $type): array
{
    return DB::table('fn_invoices as i')
        ->join('ac_enrollments as e', 'e.id', '=', 'i.enrollment_id')->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('i.fee_type_id', $type->id)
        ->orderBy('p.name')->orderBy('i.period')
        ->get(['p.name', 'i.period', 'i.status'])
        ->map(fn ($row): string => implode(' ', array_filter([$row->name, substr((string) $row->period, 0, 7), $row->status])))
        ->all();
}

test('jenis biaya "Hanya penghuni asrama": dipilih saat membuat, tercatat di audit, tampil di pengaturan', function () {
    expect($this->makan->dormitory_only)->toBeTrue()
        ->and($this->spp->dormitory_only)->toBeFalse()
        ->and(json_decode((string) DB::table('audit_logs')->where('action', FinanceAuditAction::FEE_TYPE_CREATED)->where('subject_id', $this->makan->id)->value('changes'), true))
        ->toMatchArray(['kode' => 'MAKAN', 'sifat' => 'BULANAN', 'hanya_penghuni_asrama' => true]);

    // Mengubah nama tidak mengubah tanda.
    $this->patch(route('finance.fee-types.update', ['type' => $this->makan->id]), ['name' => 'Makan asrama', 'dormitory_only' => '0'])->assertSessionHasNoErrors();
    expect($this->makan->refresh()->dormitory_only)->toBeTrue();
});

test('tagihan bulanan: hanya santri yang tinggal minimal 1 hari di asrama pada bulan itu; jenis biasa tidak terpengaruh', function () {
    $this->getJson(route('finance.invoices.preview', fndMonthly($this, $this->makan, '2026-07', '2026-12')))
        ->assertOk()
        ->assertJsonPath('summary.dormitory_only', true)
        ->assertJsonPath('summary.students', 5)
        ->assertJsonPath('summary.created', 18)
        ->assertJsonPath('summary.not_resident', 12);
    expect(DB::table('fn_invoices')->count())->toBe(0);

    $this->post(route('finance.invoices.generate'), fndMonthly($this, $this->makan, '2026-07', '2026-12'))->assertSessionHasNoErrors();

    $invoices = fndInvoices($this->makan);
    expect($invoices)->toHaveCount(18)
        ->and(array_values(array_filter($invoices, fn (string $row): bool => str_starts_with($row, 'Eko'))))->toBe(['Eko 2026-07 TERBUKA', 'Eko 2026-08 TERBUKA', 'Eko 2026-09 TERBUKA'])
        ->and(array_values(array_filter($invoices, fn (string $row): bool => str_starts_with($row, 'Budi'))))->toBe(['Budi 2026-10 TERBUKA', 'Budi 2026-11 TERBUKA', 'Budi 2026-12 TERBUKA'])
        ->and(array_filter($invoices, fn (string $row): bool => str_starts_with($row, 'Dodi')))->toBe([]);

    // SPP biasa tetap untuk semua siswa.
    $this->getJson(route('finance.invoices.preview', fndMonthly($this, $this->spp, '2026-07', '2026-07')))
        ->assertJsonPath('summary.created', 5)->assertJsonPath('summary.dormitory_only', false)->assertJsonPath('summary.not_resident', 0);

    // Batas "tinggal minimal 1 hari": keluar 10 Sep → masih tinggal di September, tidak di Oktober.
    $residents = app(DormitoryResidents::class);
    $eko = $this->person['Eko']->membership_id;
    expect($residents->residentsDuring($this->tenant->id, $this->smp->id, [$eko], '2026-09-01', '2026-09-30'))->toBe([$eko])
        ->and($residents->residentsDuring($this->tenant->id, $this->smp->id, [$eko], '2026-09-10', '2026-09-30'))->toBe([])
        // Asrama Unit 3 tidak berlaku untuk lembaga di Unit 2.
        ->and($residents->residentsDuring($this->tenant->id, CoreFixtures::node($this->tenant, 'PONPES-U2')->id, [$eko], '2026-09-01', '2026-09-30'))->toBe([]);
});

test('tagihan sekali bayar: hanya santri yang tinggal di asrama pada tanggal acuan', function () {
    $once = fn (array $target): array => ['organization_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'fee_type_id' => $this->pangkal->id, 'due_date' => '2026-11-30'] + $target;

    $this->getJson(route('finance.invoices.preview-once', $once(['target' => 'lembaga'])))
        ->assertOk()->assertJsonPath('summary.created', 3)->assertJsonPath('summary.not_resident', 2)->assertJsonPath('summary.dormitory_only', true);
    $this->getJson(route('finance.invoices.preview-once', $once(['target' => 'siswa', 'enrollment_id' => $this->person['Dodi']->enrollment_id])))
        ->assertOk()->assertJsonPath('summary.created', 0)->assertJsonPath('summary.not_resident', 1);

    $this->post(route('finance.invoices.generate-once'), $once(['target' => 'lembaga']))->assertSessionHasNoErrors();
    expect(fndInvoices($this->pangkal))->toBe(['Ahmad TERBUKA', 'Budi TERBUKA', 'Fajar TERBUKA']);
});

test('keluar asrama: biaya asrama bulan sesudahnya yang belum dibayar batal otomatis; mutasi, yang sudah dibayar, SPP & sekali bayar tidak', function () {
    foreach ([$this->makan, $this->spp] as $type) {
        $this->post(route('finance.invoices.generate'), fndMonthly($this, $type, '2026-11', '2027-01'))->assertSessionHasNoErrors();
    }

    $this->post(route('finance.invoices.generate-once'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'fee_type_id' => $this->pangkal->id, 'due_date' => '2026-11-30', 'target' => 'lembaga',
    ])->assertSessionHasNoErrors();

    // Fajar sudah mencicil biaya asrama Desember.
    DB::table('fn_invoices')->where('enrollment_id', $this->person['Fajar']->enrollment_id)->where('fee_type_id', $this->makan->id)->where('period', '2026-12-01')
        ->update(['paid_amount' => 100000]);

    fndCheckOut($this, 'Ahmad', '2026-11-05');
    fndCheckOut($this, 'Fajar', '2026-11-10');
    // Budi pindah ke asrama milik SMP sendiri: masih penghuni asrama yang sah → tidak dibatalkan.
    $this->post(route('dormitory.mutations.emergency', ['placement' => Placement::query()->where('membership_id', $this->person['Budi']->membership_id)->where('status', 'AKTIF')->sole()->id]), [
        'to_room_id' => $this->room['S-101'], 'emergency_category' => 'FASILITAS', 'reason' => 'Kamar A-101 diperbaiki',
    ])->assertSessionHasNoErrors();

    expect(fndInvoices($this->makan))->toBe([
        'Ahmad 2026-11 TERBUKA', 'Ahmad 2026-12 BATAL', 'Ahmad 2027-01 BATAL',
        'Budi 2026-11 TERBUKA', 'Budi 2026-12 TERBUKA', 'Budi 2027-01 TERBUKA',
        'Fajar 2026-11 TERBUKA', 'Fajar 2026-12 TERBUKA', 'Fajar 2027-01 BATAL',
    ])
        ->and(DB::table('fn_invoices')->where('status', 'BATAL')->distinct()->pluck('void_reason')->all())->toBe([VoidDormitoryInvoicesAfterLeaving::REASON])
        // SPP dan uang pangkal (sekali bayar) tidak disentuh.
        ->and(DB::table('fn_invoices')->whereIn('fee_type_id', [$this->spp->id, $this->pangkal->id])->where('status', 'BATAL')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::INVOICES_VOIDED_ON_DORMITORY_EXIT)->count())->toBe(2)
        ->and(json_decode((string) DB::table('audit_logs')->where('action', FinanceAuditAction::INVOICES_VOIDED_ON_DORMITORY_EXIT)->where('subject_id', $this->person['Ahmad']->enrollment_id)->value('changes'), true))
        ->toEqual(['tanggal_keluar_asrama' => '2026-11-05', 'jumlah' => 2]);

    // Masuk asrama lagi lalu keluar: tagihan yang sudah batal tidak dipulihkan, dan tidak ada yang dibatalkan dua kali.
    $this->post(route('dormitory.placements.store', ['room' => $this->room['A-101']]), [
        'membership_id' => $this->person['Ahmad']->membership_id, 'category' => 'PENGHUNI', 'mode' => 'langsung', 'date' => '2026-11-12',
    ])->assertSessionHasNoErrors();
    fndCheckOut($this, 'Ahmad', '2026-11-14');
    expect(DB::table('audit_logs')->where('action', FinanceAuditAction::INVOICES_VOIDED_ON_DORMITORY_EXIT)->count())->toBe(2);
});
