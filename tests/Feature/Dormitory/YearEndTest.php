<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Calendar\AcademicYearData;
use Modules\Academic\Application\Calendar\ManageCalendars;
use Modules\Academic\Application\Calendar\SaveAcademicYear;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Mutation\Mutation;
use Modules\Dormitory\Domain\Placement\Placement;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * DM-5a (PRD-008 §19): Perlu ditinjau, Proses akhir tahun, Tempatkan banyak.
 *
 * Data uji (hari ini 15 Nov 2026): kalender yayasan punya 2025/2026 (berakhir 30 Jun 2026) dan 2026/2027 (berjalan).
 * "Asrama Putra Unit 1" → Gedung A (putra) → A-101 (3 bed, Kosongkan) & A-102 (2 bed, Tetap).
 * Santri Unit 1 (putra): Ahmad, Budi, Eko, Fajar.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $calendar = app(ManageCalendars::class)->ensureDefault($this->tenant->id);
    app(SaveAcademicYear::class)->create($this->tenant->id, $calendar->id, new AcademicYearData(
        name: '2025/2026',
        startDate: '2025-07-01',
        endDate: '2026-06-30',
        terms: [
            ['name' => 'Semester Ganjil', 'start_date' => '2025-07-01', 'end_date' => '2025-12-31'],
            ['name' => 'Semester Genap', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30'],
        ],
    ));

    $this->admin = DevAccounts::user('admin.yayasan');
    $this->actingAs($this->admin);

    $this->post(route('dormitory.dormitories.store'), ['organization_id' => CoreFixtures::node($this->tenant, 'PONPES-U1')->id, 'code' => 'ASR-U1', 'name' => 'Asrama Putra Unit 1'])->assertSessionHasNoErrors();
    $this->dorm = Dormitory::query()->sole();
    $this->post(route('dormitory.buildings.store', ['dormitory' => $this->dorm->id]), ['code' => 'A', 'name' => 'Gedung A', 'gender' => 'L'])->assertSessionHasNoErrors();
    $buildingId = (string) DB::table('dm_buildings')->value('id');
    $this->room = [];

    foreach ([['A-101', 3, 'KOSONGKAN'], ['A-102', 2, 'TETAP']] as [$code, $beds, $policy]) {
        $this->post(route('dormitory.rooms.store', ['building' => $buildingId]), ['code' => $code, 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI', 'year_end_policy' => $policy])
            ->assertSessionHasNoErrors();
        $room = Room::query()->where('code', $code)->sole();
        $this->post(route('dormitory.resources.store', ['room' => $room->id]), ['kind' => 'bed', 'prefix' => 'B', 'start' => 1, 'count' => $beds])->assertSessionHasNoErrors();
        $this->room[$code] = $room->id;
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

function dmyPlace(mixed $test, string $name, string $room, string $date, string $mode = 'langsung'): void
{
    $test->post(route('dormitory.placements.store', ['room' => $test->room[$room]]), [
        'membership_id' => $test->student[$name], 'category' => 'PENGHUNI', 'mode' => $mode, 'date' => $date,
    ])->assertSessionHasNoErrors();
}

function dmyUser(mixed $test, string $nodeCode, string $roleKey): User
{
    $user = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($user, $test->tenant), CoreFixtures::node($test->tenant, $nodeCode)), $roleKey);
    $test->flushSession();

    return $user;
}

function dmyCloseEnrollment(mixed $test, string $name): void
{
    $student = DB::table('ac_students')->where('membership_id', $test->student[$name])->first();
    $enrollment = (string) DB::table('ac_enrollments')->where('student_id', $student->id)->whereNull('end_date')->value('id');
    $test->post(route('academic.enrollments.close', ['student' => $student->id, 'enrollment' => $enrollment]), ['end_date' => '2026-11-10', 'end_reason' => 'LULUS'])
        ->assertSessionHasNoErrors();
}

test('Perlu ditinjau: dihitung dari pendaftaran terbuka; tampil di kamar, saringan Penghuni, kartu Beranda; hilang bila didaftarkan lagi', function () {
    dmyPlace($this, 'Ahmad', 'A-101', '2026-08-01');
    dmyPlace($this, 'Budi', 'A-101', '2026-08-01');
    dmyCloseEnrollment($this, 'Ahmad');

    $this->get(route('dormitory.rooms.show', ['room' => $this->room['A-101']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('holders.0.name', 'Ahmad')->where('holders.0.needs_review', true)
            ->where('holders.1.name', 'Budi')->where('holders.1.needs_review', false));
    $this->get(route('dormitory.residents.index', ['tinjau' => '1']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.review', true)->where('residents.total', 1)->where('residents.data.0.name', 'Ahmad'));
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cards', fn ($cards) => collect($cards)->firstWhere('key', 'dormitory.residents_review')['value'] === 1
                && collect($cards)->firstWhere('key', 'dormitory.rooms_over_capacity')['value'] === 0));

    // Riwayat (Selesai) tidak pernah ditandai.
    $this->post(route('dormitory.placements.check-out', ['placement' => Placement::query()->where('membership_id', $this->student['Ahmad'])->sole()->id]), ['date' => '2026-11-15', 'end_reason' => 'KELUAR_LEMBAGA'])
        ->assertSessionHasNoErrors();
    $this->get(route('dormitory.residents.person', ['membership' => $this->student['Ahmad']]))
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.needs_review', false));
});

test('akhir tahun: hanya tahun yang sudah berakhir; pratinjau per kamar; Kosongkan mengakhiri santri yang masuk sebelum akhir tahun', function () {
    dmyPlace($this, 'Ahmad', 'A-101', '2026-01-10');
    dmyPlace($this, 'Eko', 'A-101', '2026-08-01');
    dmyPlace($this, 'Budi', 'A-102', '2026-02-01');
    dmyPlace($this, 'Fajar', 'A-101', '', 'rencana');
    $this->post(route('dormitory.mutations.store', ['placement' => Placement::query()->where('membership_id', $this->student['Ahmad'])->sole()->id]), ['to_room_id' => $this->room['A-102'], 'reason' => 'Pindah kelas'])
        ->assertSessionHasNoErrors();
    dmyCloseEnrollment($this, 'Budi');

    $this->get(route('dormitory.year-end.show', ['dormitory' => $this->dorm->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dormitory/year-end')
            ->has('years', 1)->where('years.0.name', '2025/2026')->where('years.0.end_date', '2026-06-30')->where('preview', null));
    $this->get(route('dormitory.year-end.show', ['dormitory' => $this->dorm->id, 'tahun' => '2026/2027']))
        ->assertInertia(fn (Assert $page) => $page->where('preview', null)->where('previewError', 'Pilih tahun ajaran yang sudah berakhir dari daftar.'));
    $this->get(route('dormitory.year-end.show', ['dormitory' => $this->dorm->id, 'tahun' => '2025/2026']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('preview.totals', ['emptied' => 1, 'kept' => 1, 'review' => 1])
            ->where('preview.rooms.0.code', 'A-101')->where('preview.rooms.0.emptied', 1)
            ->where('preview.rooms.1.code', 'A-102')->where('preview.rooms.1.kept', 1)->where('preview.rooms.1.review', 1));

    $this->post(route('dormitory.year-end.run', ['dormitory' => $this->dorm->id]), ['year' => '2025/2026'])
        ->assertRedirect('/asrama/'.$this->dorm->id.'?tab=pengaturan')->assertSessionHasNoErrors();

    $status = fn (string $name): array => Placement::query()->where('membership_id', $this->student[$name])->get()
        ->map(fn (Placement $placement) => [$placement->status->value, $placement->end_reason?->value, $placement->check_out_on?->toDateString()])->all();
    expect($status('Ahmad'))->toBe([['SELESAI', 'AKHIR_TAHUN', '2026-06-30']])
        ->and($status('Eko'))->toBe([['AKTIF', null, null]])
        ->and($status('Budi'))->toBe([['AKTIF', null, null]])
        ->and($status('Fajar'))->toBe([['DIRENCANAKAN', null, null]])
        ->and(Mutation::query()->sole()->status->value)->toBe('DIBATALKAN')
        ->and(json_decode((string) DB::table('audit_logs')->where('action', DormitoryAuditAction::YEAR_END_RUN)->value('changes'), true))
        ->toEqual(['asrama' => 'ASR-U1', 'tahun_ajaran' => '2025/2026', 'kamar' => 2, 'emptied' => 1, 'kept' => 1, 'review' => 1]);

    $this->get(route('dormitory.dormitories.show', ['dormitory' => $this->dorm->id]))
        ->assertInertia(fn (Assert $page) => $page->where('canRunYearEnd', true)->where('yearEndRuns.0.year', '2025/2026')->where('yearEndRuns.0.emptied', 1));
    $this->get(route('dormitory.year-end.show', ['dormitory' => $this->dorm->id]))->assertInertia(fn (Assert $page) => $page->where('years.0.processed', true));
    $this->post(route('dormitory.year-end.run', ['dormitory' => $this->dorm->id]), ['year' => '2025/2026'])
        ->assertSessionHasErrors(['year' => 'Asrama ini sudah diproses untuk tahun ajaran 2025/2026.']);
    $this->post(route('dormitory.year-end.run', ['dormitory' => $this->dorm->id]), ['year' => '2026/2027'])
        ->assertSessionHasErrors(['year' => 'Pilih tahun ajaran yang sudah berakhir dari daftar.']);
});

test('akhir tahun: hak akses — kepala asrama 403, pengurus unit lain 404; satu proses per tahun di database', function () {
    $this->actingAs(dmyUser($this, 'PONPES-U1', DormitoryAccess::ROLE_KEPALA_ASRAMA))
        ->get(route('dormitory.year-end.show', ['dormitory' => $this->dorm->id]))->assertForbidden();
    $this->actingAs(dmyUser($this, 'PONPES-U2', DormitoryAccess::ROLE_PENGURUS_ASRAMA))
        ->post(route('dormitory.year-end.run', ['dormitory' => $this->dorm->id]), ['year' => '2025/2026'])->assertNotFound();
    $this->actingAs(dmyUser($this, 'PONPES-U1', DormitoryAccess::ROLE_PENGURUS_ASRAMA))
        ->post(route('dormitory.year-end.run', ['dormitory' => $this->dorm->id]), ['year' => '2025/2026'])->assertSessionHasNoErrors();

    $insert = fn (array $overrides) => DB::transaction(fn () => DB::table('dm_year_end_runs')->insert(array_merge([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => $this->dorm->organization_id, 'dormitory_id' => $this->dorm->id,
        'academic_year_name' => '2024/2025', 'academic_year_end' => '2025-06-30', 'emptied' => 0, 'kept' => 0, 'review' => 0, 'created_at' => now(), 'updated_at' => now(),
    ], $overrides)));

    expect(fn () => $insert(['academic_year_name' => '2025/2026']))->toThrow(QueryException::class)
        ->and(fn () => $insert(['organization_id' => CoreFixtures::node($this->tenant, 'PONPES-U2')->id]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['academic_year_name' => ' ']))->toThrow(QueryException::class);
    $insert([]);
    expect(DB::table('dm_year_end_runs')->count())->toBe(2);
});

test('tempatkan banyak: semua atau tidak, pesan menyebut santrinya; bed otomatis berurutan; rencana', function () {
    $many = fn (array $names, array $overrides = []) => $this->post(route('dormitory.placements.store-many', ['room' => $this->room['A-102']]), array_merge([
        'membership_ids' => array_map(fn (string $name) => $this->student[$name], $names), 'mode' => 'langsung', 'date' => '2026-11-15',
    ], $overrides));

    $many([])->assertSessionHasErrors(['membership_ids' => 'Pilih minimal satu santri.']);
    $many(['Ahmad', 'Budi', 'Eko'])->assertSessionHasErrors(['membership_ids' => 'Eko: Kamar A-102 penuh untuk santri (2 dari 2).']);
    expect(Placement::query()->count())->toBe(0);

    dmyCloseEnrollment($this, 'Fajar');
    $many(['Ahmad', 'Fajar'])->assertSessionHasErrors(['membership_ids' => 'Fajar tidak punya pendaftaran aktif di lembaga milik Unit 1.']);
    $many(['Ahmad', 'Budi'], ['date' => '2026-11-16'])->assertSessionHasErrors(['date' => 'Ahmad: Tanggal masuk tidak boleh di masa depan.']);
    expect(Placement::query()->count())->toBe(0);

    $many(['Ahmad', 'Budi'])->assertRedirect()->assertSessionHasNoErrors();
    $beds = Placement::query()->orderBy('created_at')->get()->map(fn (Placement $placement) => DB::table('dm_beds')->where('id', $placement->bed_id)->value('code'))->all();
    expect($beds)->toBe(['B01', 'B02']);

    $this->post(route('dormitory.placements.store-many', ['room' => $this->room['A-101']]), ['membership_ids' => [$this->student['Eko']], 'mode' => 'rencana', 'date' => ''])
        ->assertSessionHasNoErrors();
    expect(Placement::query()->where('membership_id', $this->student['Eko'])->sole()->status->value)->toBe('DIRENCANAKAN');
});
