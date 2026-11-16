<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Report\OccupancyReport;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Placement\Placement;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * DM-6 (PRD-008 §21): Laporan hunian (hunian sekarang, riwayat pergerakan, unduhan) + unduhan daftar Penghuni.
 *
 * Data uji (hari ini 15 Nov 2026): "Asrama Putra Unit 1" (ASR-U1, Unit 1) → Gedung A (putra): A-101 (3 bed),
 * A-102 (2 bed); Gedung C (putri): C-101 tanpa bed (kapasitas 0). "Asrama Unit 2" (ASR-U2, Unit 2) → D-101 (2 bed).
 * Santri Unit 1 (putra): Ahmad, Budi, Eko, Fajar.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->actingAs($this->admin);

    $this->dorm = [];
    $this->room = [];

    foreach ([
        ['ASR-U1', 'Asrama Putra Unit 1', 'PONPES-U1', [['A', 'L', [['A-101', 3], ['A-102', 2]]], ['C', 'P', [['C-101', 0]]]]],
        ['ASR-U2', 'Asrama Unit 2', 'PONPES-U2', [['D', 'L', [['D-101', 2]]]]],
    ] as [$code, $name, $node, $buildings]) {
        $this->post(route('dormitory.dormitories.store'), ['organization_id' => CoreFixtures::node($this->tenant, $node)->id, 'code' => $code, 'name' => $name])
            ->assertSessionHasNoErrors();
        $dormitory = Dormitory::query()->where('code', $code)->sole();
        $this->dorm[$code] = $dormitory->id;

        foreach ($buildings as [$buildingCode, $gender, $rooms]) {
            $this->post(route('dormitory.buildings.store', ['dormitory' => $dormitory->id]), ['code' => $buildingCode, 'name' => "Gedung {$buildingCode}", 'gender' => $gender])
                ->assertSessionHasNoErrors();
            $buildingId = (string) DB::table('dm_buildings')->where('dormitory_id', $dormitory->id)->where('code', $buildingCode)->value('id');

            foreach ($rooms as [$roomCode, $beds]) {
                $this->post(route('dormitory.rooms.store', ['building' => $buildingId]), ['code' => $roomCode, 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI', 'year_end_policy' => 'TETAP'])
                    ->assertSessionHasNoErrors();
                $room = Room::query()->where('code', $roomCode)->sole();

                if ($beds > 0) {
                    $this->post(route('dormitory.resources.store', ['room' => $room->id]), ['kind' => 'bed', 'prefix' => $roomCode.'-', 'start' => 1, 'count' => $beds])
                        ->assertSessionHasNoErrors();
                }

                $this->room[$roomCode] = $room->id;
            }
        }
    }

    $this->student = [];

    foreach (['Ahmad', 'Budi', 'Eko', 'Fajar'] as $name) {
        $this->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => CoreFixtures::node($this->tenant, 'U1-MA')->id, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();
        $this->student[$name] = (string) DB::table('ac_students as s')->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->value('s.membership_id');
    }
});

function dmlPlace(mixed $test, string $name, string $room, string $date, string $mode = 'langsung'): string
{
    $test->post(route('dormitory.placements.store', ['room' => $test->room[$room]]), [
        'membership_id' => $test->student[$name], 'category' => 'PENGHUNI', 'mode' => $mode, 'date' => $date,
    ])->assertSessionHasNoErrors();

    return Placement::query()->where('membership_id', $test->student[$name])->latest('created_at')->firstOrFail()->id;
}

function dmlUser(mixed $test, string $nodeCode, ?string $roleKey): User
{
    $user = User::factory()->create();
    $assignment = CoreFixtures::assign(CoreFixtures::member($user, $test->tenant), CoreFixtures::node($test->tenant, $nodeCode));

    if ($roleKey !== null) {
        CoreFixtures::assignmentRole($assignment, $roleKey);
    }

    $test->flushSession();

    return $user;
}

/**
 * @return array<string, list<list<mixed>>>
 */
function dmlSheets(string $path): array
{
    $reader = new XlsxReader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }

        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();

    return $sheets;
}

test('hunian sekarang: kapasitas efektif, terisi santri/pengawas, rencana, tersedia, % hunian (kapasitas 0 tidak dibagi), kamar nonaktif', function () {
    dmlPlace($this, 'Ahmad', 'A-101', '2026-08-01');
    dmlPlace($this, 'Budi', 'A-101', '2026-08-01');
    dmlPlace($this, 'Eko', 'A-101', '', 'rencana');
    dmlPlace($this, 'Fajar', 'A-102', '2026-09-01');
    // Bed kosong A-102 rusak → kapasitas efektif A-102 = 1.
    $freeBed = DB::table('dm_beds')->where('room_id', $this->room['A-102'])->whereNotIn('id', DB::table('dm_placements')->whereNotNull('bed_id')->select('bed_id'))->sole();
    $this->patch(route('dormitory.resources.update', ['kind' => 'bed', 'resource' => $freeBed->id]), ['code' => $freeBed->code, 'status' => 'TIDAK_TERSEDIA', 'unavailable_reason' => 'RUSAK'])
        ->assertSessionHasNoErrors();

    $this->get(route('dormitory.report.index', ['asrama' => $this->dorm['ASR-U1']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dormitory/report')
            ->where('movements', null)->where('events', null)
            ->has('current.dormitories', 1)
            ->where('current.dormitories.0.buildings.0.rooms.0.code', 'A-101')
            ->where('current.dormitories.0.buildings.0.rooms.0.metrics', fn ($metrics) => $metrics['capacity'] === 3 && $metrics['active'] === 2
                && $metrics['residents'] === 2 && $metrics['planned'] === 1 && $metrics['available'] === 0 && $metrics['percent'] == 66.7)
            ->where('current.dormitories.0.buildings.0.rooms.1.metrics', fn ($metrics) => $metrics['capacity'] === 1 && $metrics['active'] === 1
                && $metrics['percent'] == 100 && $metrics['unavailable'] === 1 && $metrics['beds_available'] === 1 && $metrics['beds_total'] === 2)
            ->where('current.dormitories.0.buildings.1.gender_label', 'Putri')
            ->where('current.dormitories.0.buildings.1.rooms.0.metrics.capacity', 0)
            ->where('current.dormitories.0.buildings.1.rooms.0.metrics.percent', null)
            ->where('current.totals', fn ($totals) => $totals['rooms'] === 3 && $totals['capacity'] === 4 && $totals['active'] === 3
                && $totals['planned'] === 1 && $totals['available'] === 0 && $totals['percent'] == 75 && $totals['unavailable'] === 1 && $totals['over_capacity'] === 0));

    // Saringan putri: hanya C-101 (kapasitas 0 → persen kosong).
    $this->get(route('dormitory.report.index', ['asrama' => $this->dorm['ASR-U1'], 'jk' => 'putri']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.gender', 'putri')
            ->has('current.dormitories.0.buildings', 1)
            ->where('current.totals.capacity', 0)->where('current.totals.percent', null));

    // Kamar nonaktif yang masih berpenghuni tetap tampil, tanpa kapasitas.
    $this->post(route('dormitory.rooms.status', ['room' => $this->room['A-102']]), ['active' => '0'])->assertSessionHasNoErrors();
    $this->get(route('dormitory.report.index', ['asrama' => $this->dorm['ASR-U1'], 'jk' => 'putra']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('current.dormitories.0.buildings.0.rooms.1.counted', false)
            ->where('current.dormitories.0.buildings.0.rooms.1.metrics.capacity', 0)
            ->where('current.dormitories.0.buildings.0.rooms.1.metrics.active', 1)
            ->where('current.totals.capacity', 3)->where('current.totals.active', 3)->where('current.totals.percent', 100));
});

test('riwayat: hunian awal & akhir, masuk, keluar per alasan, mutasi (darurat dihitung sekali), daftar kejadian', function () {
    dmlPlace($this, 'Ahmad', 'A-101', '2026-10-20');
    $budi = dmlPlace($this, 'Budi', 'A-101', '2026-11-03');
    $eko = dmlPlace($this, 'Eko', 'A-102', '2026-11-05');
    $this->post(route('dormitory.placements.check-out', ['placement' => $budi]), ['date' => '2026-11-10', 'end_reason' => 'KELUAR_ASRAMA'])->assertSessionHasNoErrors();
    $this->post(route('dormitory.mutations.emergency', ['placement' => $eko]), ['to_room_id' => $this->room['A-101'], 'emergency_category' => 'KESEHATAN', 'reason' => 'Butuh kamar dekat UKS'])
        ->assertSessionHasNoErrors();

    $this->get(route('dormitory.report.index', ['asrama' => $this->dorm['ASR-U1'], 'tab' => 'riwayat', 'dari' => '2026-11-01', 'sampai' => '2026-11-15']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-11-01')->where('filters.to', '2026-11-15')->where('rangeAdjusted', false)
            ->where('movements.dormitories.0.movement', [
                'start' => 1, 'entries' => 2, 'left_dormitory' => 1, 'left_school' => 0, 'year_end' => 0, 'other' => 0,
                'mutation_in' => 1, 'mutation_out' => 1, 'emergency' => 1, 'end' => 2,
            ])
            ->where('events.total', 5)
            ->where('events.rows.0.date', '2026-11-15')
            ->where('events.rows', fn ($rows) => collect($rows)->pluck('kind_label')->sort()->values()->all() === ['Keluar · Keluar asrama', 'Masuk', 'Masuk', 'Mutasi keluar', 'Mutasi masuk']
                && collect($rows)->firstWhere('kind', 'MUTASI_MASUK')['detail'] === 'Darurat · dari A-102'
                && collect($rows)->firstWhere('kind', 'MUTASI_KELUAR')['detail'] === 'Darurat · ke A-101'));

    // Rentang lebih sempit: Eko lama masih terhitung di hunian awal.
    $this->get(route('dormitory.report.index', ['asrama' => $this->dorm['ASR-U1'], 'tab' => 'riwayat', 'dari' => '2026-11-11', 'sampai' => '2026-11-15']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('movements.dormitories.0.movement.start', 2)->where('movements.dormitories.0.movement.entries', 0)
            ->where('movements.dormitories.0.movement.end', 2)->where('events.total', 2));

    // Rentang disesuaikan: sampai di masa depan, lebih dari 1 tahun, atau tanggal tidak sah.
    $today = BusinessClock::today();
    expect(OccupancyReport::range('2026-11-01', '2026-12-31', $today))->toMatchArray(['adjusted' => true])
        ->and(OccupancyReport::range('2026-11-01', '2026-12-31', $today)['to']->toDateString())->toBe('2026-11-15')
        ->and(OccupancyReport::range('2024-01-01', '2026-11-15', $today)['from']->toDateString())->toBe('2025-11-15')
        ->and(OccupancyReport::range('2026-02-30', 'abc', $today)['from']->toDateString())->toBe('2026-11-01')
        ->and(OccupancyReport::range('2026-11-10', '2026-11-05', $today)['from']->toDateString())->toBe('2026-11-01');
});

test('unduhan: laporan hunian 3 lembar dan daftar Penghuni sesuai saringan', function () {
    dmlPlace($this, 'Ahmad', 'A-101', '2026-11-02');
    dmlPlace($this, 'Budi', 'A-102', '2026-11-03');
    dmlPlace($this, 'Eko', 'A-101', '', 'rencana');

    $response = $this->get(route('dormitory.report.export', ['asrama' => $this->dorm['ASR-U1'], 'dari' => '2026-11-01', 'sampai' => '2026-11-15']));
    $response->assertOk()->assertDownload('laporan-hunian-asr-u1-2026-11-15.xlsx');
    $sheets = dmlSheets($response->baseResponse->getFile()->getPathname());

    expect(array_keys($sheets))->toBe(['Ringkasan', 'Per kamar', 'Riwayat'])
        ->and($sheets['Ringkasan'][0][0])->toBe('Laporan hunian asrama — Asrama Putra Unit 1')
        ->and($sheets['Ringkasan'][2][0])->toBe('Asrama')
        // Asrama, pemilik, kamar 3, kapasitas 5, terisi 2, santri 2, pengawas 0, rencana 1, tersedia 2, 40%.
        ->and(array_slice($sheets['Ringkasan'][3], 0, 10))->toBe(['Asrama Putra Unit 1', 'Unit 1', 3, 5, 2, 2, 0, 1, 2, 40])
        // Hunian awal 0, masuk 2, ..., hunian akhir 2.
        ->and($sheets['Ringkasan'][3][12])->toBe(0)->and($sheets['Ringkasan'][3][13])->toBe(2)->and($sheets['Ringkasan'][3][21])->toBe(2)
        ->and($sheets['Ringkasan'][4][0])->toBe('Total')
        ->and($sheets['Per kamar'])->toHaveCount(4)
        ->and(array_slice($sheets['Per kamar'][1], 0, 5))->toBe(['Asrama Putra Unit 1', 'Gedung A (A)', 'Putra', 'A-101', 'Aktif'])
        ->and($sheets['Riwayat'])->toHaveCount(3)
        ->and($sheets['Riwayat'][1][1])->toBe('Budi');

    $response = $this->get(route('dormitory.residents.export', ['asrama' => $this->dorm['ASR-U1'], 'status' => 'aktif']));
    $response->assertOk()->assertDownload('penghuni-asrama-2026-11-15.xlsx');
    $rows = dmlSheets($response->baseResponse->getFile()->getPathname())['Penghuni'];

    expect($rows)->toHaveCount(3)
        ->and($rows[0][1])->toBe('Nama')
        ->and([$rows[1][1], $rows[1][6], $rows[1][9], $rows[1][10]])->toBe(['Ahmad', 'A-101', 'Aktif', '02/11/2026'])
        ->and($rows[2][1])->toBe('Budi');
});

test('hak akses: hanya asrama dalam cakupan; Kepala Asrama boleh lihat; tanpa izin lihat penghuni 403', function () {
    $pengurus2 = dmlUser($this, 'PONPES-U2', DormitoryAccess::ROLE_PENGURUS_ASRAMA);
    $this->actingAs($pengurus2)
        ->get(route('dormitory.report.index', ['asrama' => $this->dorm['ASR-U1']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('options.dormitories', [['value' => $this->dorm['ASR-U2'], 'label' => 'Asrama Unit 2']])
            // Asrama di luar cakupan diabaikan → kembali ke semua asrama yang terlihat.
            ->where('filters.dormitory', null)
            ->has('current.dormitories', 1)->where('current.dormitories.0.code', 'ASR-U2'));
    $this->actingAs($pengurus2)
        ->get(route('dormitory.report.export', ['asrama' => $this->dorm['ASR-U1']]))
        ->assertOk()->assertDownload('laporan-hunian-semua-asrama-2026-11-15.xlsx');

    $kepala = dmlUser($this, 'PONPES-U1', DormitoryAccess::ROLE_KEPALA_ASRAMA);
    $this->actingAs($kepala)->get(route('dormitory.report.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('current.dormitories', 1)->where('current.dormitories.0.code', 'ASR-U1'));
    $this->actingAs($kepala)->get(route('dormitory.residents.export'))->assertOk();

    $staff = dmlUser($this, 'U1-MA', null);
    $this->actingAs($staff)->get(route('dormitory.report.index'))->assertForbidden();
    $this->actingAs($staff)->get(route('dormitory.report.export'))->assertForbidden();
    $this->actingAs($staff)->get(route('dormitory.residents.export'))->assertForbidden();
});
