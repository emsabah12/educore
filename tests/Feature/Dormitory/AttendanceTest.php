<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Domain\Attendance\AttendanceKind;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Placement\Placement;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * DM-7 Absen asrama (PRD-008 §22).
 *
 * Data uji (hari ini 15 Nov 2026): "Asrama Putra Unit 1" → Gedung A (putra) → A-101 (Santri + pengawas, 1 slot
 * pengawas, 4 bed) dan A-102 (khusus santri, 2 bed). Santri: Ahmad & Budi (A-101), Eko (A-102, lalu mutasi darurat
 * ke A-101 hari ini). Pengawas tinggal di A-101: Ustadz Hasan. Wali kamar A-101: akun `guru`. Jenis absen: Subuh, Malam.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->actingAs($this->admin);

    $this->post(route('dormitory.dormitories.store'), ['organization_id' => CoreFixtures::node($this->tenant, 'PONPES-U1')->id, 'code' => 'ASR-U1', 'name' => 'Asrama Putra Unit 1'])
        ->assertSessionHasNoErrors();
    $this->dorm = Dormitory::query()->sole();
    $this->post(route('dormitory.buildings.store', ['dormitory' => $this->dorm->id]), ['code' => 'A', 'name' => 'Gedung A', 'gender' => 'L'])->assertSessionHasNoErrors();
    $buildingId = (string) DB::table('dm_buildings')->value('id');
    $this->room = [];

    foreach ([['A-101', 'SANTRI_PENGAWAS', 4], ['A-102', 'KHUSUS_SANTRI', 2]] as [$code, $composition, $beds]) {
        $this->post(route('dormitory.rooms.store', ['building' => $buildingId]), array_filter([
            'code' => $code, 'capacity_basis' => 'BED', 'composition' => $composition, 'supervisor_slots' => $composition === 'SANTRI_PENGAWAS' ? '1' : null,
        ]))->assertSessionHasNoErrors();
        $room = Room::query()->where('code', $code)->sole();
        $this->post(route('dormitory.resources.store', ['room' => $room->id]), ['kind' => 'bed', 'prefix' => 'B', 'start' => 1, 'count' => $beds])->assertSessionHasNoErrors();
        $this->room[$code] = $room->id;
    }

    $this->person = [];

    foreach (['Ahmad', 'Budi', 'Eko'] as $name) {
        $this->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => CoreFixtures::node($this->tenant, 'U1-MA')->id, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();
        $this->person[$name] = (string) DB::table('ac_students as s')->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->value('s.membership_id');
    }

    $this->guru = DevAccounts::user('guru');
    $guruMembership = (string) Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->value('id');
    dmaEmployee($this, 'Ustadz Guru', $guruMembership);
    $this->person['Hasan'] = dmaEmployee($this, 'Ustadz Hasan');

    dmaPlace($this, 'Ahmad', 'A-101', '2026-11-01');
    dmaPlace($this, 'Budi', 'A-101', '2026-11-10');
    dmaPlace($this, 'Eko', 'A-102', '2026-11-01');
    dmaPlace($this, 'Hasan', 'A-101', '2026-11-01', 'PENGAWAS');
    $this->post(route('dormitory.mutations.emergency', ['placement' => Placement::query()->where('membership_id', $this->person['Eko'])->sole()->id]), [
        'to_room_id' => $this->room['A-101'], 'emergency_category' => 'KESEHATAN', 'reason' => 'Butuh kamar dekat UKS',
    ])->assertSessionHasNoErrors();
    $this->post(route('dormitory.supervisors.store', ['room' => $this->room['A-101']]), ['membership_id' => $guruMembership, 'date' => '2026-11-01'])->assertSessionHasNoErrors();

    foreach (['Subuh', 'Malam'] as $name) {
        $this->post(route('dormitory.attendance-kinds.store', ['dormitory' => $this->dorm->id]), ['name' => $name])->assertSessionHasNoErrors();
    }

    $this->kind = AttendanceKind::query()->pluck('id', 'name')->all();
});

function dmaEmployee(mixed $test, string $name, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Male, birthPlace: null, birthDate: null, phone: null,
        address: null, employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: CoreFixtures::node($test->tenant, 'U1-MA')->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->membership_id;
}

function dmaPlace(mixed $test, string $name, string $room, string $date, string $category = 'PENGHUNI'): void
{
    $test->post(route('dormitory.placements.store', ['room' => $test->room[$room]]), [
        'membership_id' => $test->person[$name], 'category' => $category, 'mode' => 'langsung', 'date' => $date,
    ])->assertSessionHasNoErrors();
}

/** Penempatan yang berlaku pada hari ini (setelah mutasi). */
function dmaPlacement(mixed $test, string $name): string
{
    return Placement::query()->where('membership_id', $test->person[$name])->where('status', 'AKTIF')->sole()->id;
}

/**
 * @param  array<string, string>  $statuses  nama => status
 * @param  array<string, string>  $notes
 */
function dmaSave(mixed $test, string $room, string $kind, string $date, array $statuses, array $notes = []): mixed
{
    $entries = [];

    foreach ($statuses as $name => $status) {
        $entries[dmaPlacement($test, $name)] = ['status' => $status, 'note' => $notes[$name] ?? ''];
    }

    return $test->post(route('dormitory.attendance.save', ['room' => $test->room[$room]]), ['kind' => $test->kind[$kind], 'date' => $date, 'entries' => $entries]);
}

function dmaUser(mixed $test, string $nodeCode, string $roleKey): User
{
    $user = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($user, $test->tenant), CoreFixtures::node($test->tenant, $nodeCode)), $roleKey);
    $test->flushSession();

    return $user;
}

function dmaAsGuru(mixed $test): void
{
    $test->flushSession();
    $test->actingAs($test->guru);
    DevAccounts::chooseWorkspace($test, $test->guru, 'U1-MA');
}

test('jenis absen: nama unik, maks. 5 aktif, ubah, urutan, nonaktif, hapus hanya yang belum dipakai, hanya pengelola fasilitas', function () {
    $store = fn (string $name) => $this->post(route('dormitory.attendance-kinds.store', ['dormitory' => $this->dorm->id]), ['name' => $name]);

    $store('subuh')->assertSessionHasErrors(['name' => 'Jenis absen "subuh" sudah ada di asrama ini.']);
    $store('  ')->assertSessionHasErrors(['name' => 'Nama jenis absen wajib diisi.']);
    $store('Dhuha')->assertSessionHasNoErrors();
    $store('Ashar')->assertSessionHasNoErrors();
    $store('Isya')->assertSessionHasNoErrors();
    $store('Tahajud')->assertSessionHasErrors(['name' => 'Paling banyak 5 jenis absen aktif per asrama. Nonaktifkan salah satu dulu.']);

    $dhuha = AttendanceKind::query()->where('name', 'Dhuha')->sole();
    $this->post(route('dormitory.attendance-kinds.status', ['kind' => $dhuha->id]), ['active' => '0'])->assertSessionHasNoErrors();
    $store('Tahajud')->assertSessionHasNoErrors();
    $this->post(route('dormitory.attendance-kinds.status', ['kind' => $dhuha->id]), ['active' => '1'])->assertSessionHasErrors('name');

    $this->patch(route('dormitory.attendance-kinds.update', ['kind' => $this->kind['Malam']]), ['name' => 'Malam (Isya)'])->assertSessionHasNoErrors();
    $this->post(route('dormitory.attendance-kinds.move', ['kind' => $this->kind['Malam']]), ['direction' => 'up'])->assertSessionHasNoErrors();

    $this->get(route('dormitory.dormitories.show', ['dormitory' => $this->dorm->id, 'tab' => 'pengaturan']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendanceKinds', fn ($rows) => collect($rows)->pluck('name')->take(2)->all() === ['Malam (Isya)', 'Subuh']
                && collect($rows)->firstWhere('name', 'Dhuha')['is_active'] === false));

    // Sudah dipakai → tidak bisa dihapus; yang belum dipakai bisa.
    dmaSave($this, 'A-101', 'Subuh', '2026-11-15', ['Ahmad' => 'H', 'Budi' => 'H', 'Eko' => 'H', 'Hasan' => 'H'])->assertSessionHasNoErrors();
    $this->delete(route('dormitory.attendance-kinds.destroy', ['kind' => $this->kind['Subuh']]))
        ->assertSessionHasErrors(['form' => 'Jenis absen Subuh sudah dipakai, jadi tidak bisa dihapus. Nonaktifkan saja.']);
    $this->delete(route('dormitory.attendance-kinds.destroy', ['kind' => $dhuha->id]))->assertSessionHasNoErrors();
    expect(AttendanceKind::query()->whereKey($dhuha->id)->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', DormitoryAuditAction::ATTENDANCE_KIND_CHANGED)->count())->toBe(9);

    // Kepala Asrama hanya lihat fasilitas → 403; pengurus unit lain → 404.
    $this->actingAs(dmaUser($this, 'PONPES-U1', DormitoryAccess::ROLE_KEPALA_ASRAMA))
        ->post(route('dormitory.attendance-kinds.store', ['dormitory' => $this->dorm->id]), ['name' => 'Ashar 2'])->assertForbidden();
    $this->actingAs(dmaUser($this, 'PONPES-U2', DormitoryAccess::ROLE_PENGURUS_ASRAMA))
        ->patch(route('dormitory.attendance-kinds.update', ['kind' => $this->kind['Subuh']]), ['name' => 'X'])->assertNotFound();
});

test('lembar absen: penghuni kamar pada tanggal itu (santri & pengawas, mengikuti mutasi); simpan semua sekaligus; koreksi', function () {
    $sheet = fn (string $date) => $this->get(route('dormitory.attendance.sheet', ['room' => $this->room['A-101'], 'jenis' => $this->kind['Subuh'], 'tanggal' => $date]));

    $sheet('2026-11-05')->assertOk()->assertInertia(fn (Assert $page) => $page->component('dormitory/attendance-sheet')
        ->where('asSupervisor', false)->where('window.min', '2025-11-15')->where('date', '2026-11-05')
        ->where('sheet.rows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ahmad', 'Ustadz Hasan'])
        ->where('sheet.rows.1.category', 'PENGAWAS')->where('sheet.session', null));
    $sheet('2026-11-12')->assertInertia(fn (Assert $page) => $page->where('sheet.rows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ahmad', 'Budi', 'Ustadz Hasan']));
    $sheet('2026-11-15')->assertInertia(fn (Assert $page) => $page->where('sheet.rows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ahmad', 'Budi', 'Eko', 'Ustadz Hasan']
        && collect($rows)->every(fn ($row) => $row['status'] === 'H')));
    // Eko sudah pindah: A-102 kosong hari ini, tetapi masih berisi Eko kemarin.
    $this->get(route('dormitory.attendance.sheet', ['room' => $this->room['A-102'], 'tanggal' => '2026-11-15']))->assertInertia(fn (Assert $page) => $page->has('sheet.rows', 0));
    $this->get(route('dormitory.attendance.sheet', ['room' => $this->room['A-102'], 'tanggal' => '2026-11-14']))->assertInertia(fn (Assert $page) => $page->where('sheet.rows.0.name', 'Eko'));

    dmaSave($this, 'A-101', 'Subuh', '2026-11-15', ['Ahmad' => 'H', 'Budi' => 'S', 'Eko' => 'T', 'Hasan' => 'I'], ['Budi' => 'Demam sejak malam'])
        ->assertSessionHasNoErrors();
    $sheet('2026-11-15')->assertInertia(fn (Assert $page) => $page
        ->where('sheet.rows.1.status', 'S')->where('sheet.rows.1.note', 'Demam sejak malam')->where('sheet.rows.2.status', 'T')
        ->where('sheet.session.recorded_by', fn ($name) => is_string($name) && $name !== ''));
    expect(json_decode((string) DB::table('audit_logs')->where('action', DormitoryAuditAction::ATTENDANCE_RECORDED)->value('changes'), true))
        ->toEqual(['kamar' => 'A-101', 'jenis' => 'Subuh', 'tanggal' => '2026-11-15', 'hadir' => 1, 'izin' => 1, 'sakit' => 1, 'tidak_ada' => 1, 'koreksi' => false]);

    // Koreksi menimpa sesi yang sama.
    dmaSave($this, 'A-101', 'Subuh', '2026-11-15', ['Ahmad' => 'H', 'Budi' => 'H', 'Eko' => 'H', 'Hasan' => 'H'])->assertSessionHasNoErrors();
    expect(DB::table('dm_attendance_sessions')->count())->toBe(1)
        ->and(DB::table('dm_attendance_records')->where('status', 'H')->count())->toBe(4)
        ->and(json_decode((string) DB::table('audit_logs')->where('action', DormitoryAuditAction::ATTENDANCE_RECORDED)->orderByDesc('created_at')->orderByDesc('id')->value('changes'), true)['koreksi'])->toBeTrue();

    // Penolakan: belum semua diberi status, penghuni asing, tanggal masa depan, jenis nonaktif.
    dmaSave($this, 'A-101', 'Malam', '2026-11-15', ['Ahmad' => 'H', 'Budi' => 'H'])->assertSessionHasErrors(['form' => 'Pilih status kehadiran untuk: Eko, Ustadz Hasan.']);
    $this->post(route('dormitory.attendance.save', ['room' => $this->room['A-102']]), ['kind' => $this->kind['Malam'], 'date' => '2026-11-14', 'entries' => [dmaPlacement($this, 'Ahmad') => ['status' => 'H']]])
        ->assertSessionHasErrors(['form' => 'Daftar penghuni kamar ini berubah sejak halaman dibuka. Muat ulang halaman lalu isi lagi.']);
    dmaSave($this, 'A-101', 'Malam', '2026-11-16', ['Ahmad' => 'H'])->assertSessionHasErrors(['date' => 'Absen tidak bisa diisi untuk tanggal di masa depan.']);
    dmaSave($this, 'A-101', 'Malam', '2026-11-15', ['Ahmad' => 'X', 'Budi' => 'H', 'Eko' => 'H', 'Hasan' => 'H'])->assertSessionHasErrors(['form' => 'Pilih status kehadiran untuk: Ahmad.']);
    $this->post(route('dormitory.attendance-kinds.status', ['kind' => $this->kind['Malam']]), ['active' => '0'])->assertSessionHasNoErrors();
    dmaSave($this, 'A-101', 'Malam', '2026-11-15', ['Ahmad' => 'H', 'Budi' => 'H', 'Eko' => 'H', 'Hasan' => 'H'])
        ->assertSessionHasErrors(['kind' => 'Pilih jenis absen yang aktif di asrama ini.']);

    // Halaman Absen asrama: semua kamar untuk satu tanggal + jenis.
    $this->get(route('dormitory.attendance.index', ['asrama' => $this->dorm->id, 'jenis' => $this->kind['Subuh'], 'tanggal' => '2026-11-15']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dormitory/attendance')->where('canFill', true)->has('kinds', 1)
            ->where('fill.rooms.0.code', 'A-101')->where('fill.rooms.0.state.filled', true)->where('fill.rooms.0.state.counts.H', 4)
            ->where('fill.rooms.1.code', 'A-102')->where('fill.rooms.1.state.filled', false)->where('fill.rooms.1.state.residents', 0));

    // Database menolak status di luar H/I/S/T.
    expect(fn () => DB::table('dm_attendance_records')->where('status', 'H')->limit(1)->update(['status' => 'A']))->toThrow(QueryException::class);
});

test('wali kamar: hanya kamarnya, 7 hari terakhir; rekap kamarnya di Kamar saya', function () {
    dmaAsGuru($this);

    $this->get(route('dormitory.attendance.sheet', ['room' => $this->room['A-101'], 'tanggal' => '2026-11-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('asSupervisor', true)->where('window.min', '2026-11-09')->where('date', '2026-11-15')->has('sheet.rows', 4));
    dmaSave($this, 'A-101', 'Subuh', '2026-11-08', ['Ahmad' => 'H', 'Budi' => 'H', 'Hasan' => 'H'])
        ->assertSessionHasErrors(['date' => 'Wali kamar hanya bisa mengisi atau mengoreksi absen 7 hari terakhir. Minta pengurus asrama untuk tanggal yang lebih lama.']);
    dmaSave($this, 'A-101', 'Subuh', '2026-11-15', ['Ahmad' => 'H', 'Budi' => 'H', 'Eko' => 'I', 'Hasan' => 'H'])->assertSessionHasNoErrors();

    // Kamar lain dan halaman pengurus tertutup.
    $this->get(route('dormitory.attendance.sheet', ['room' => $this->room['A-102']]))->assertNotFound();
    $this->post(route('dormitory.attendance.save', ['room' => $this->room['A-102']]), ['kind' => $this->kind['Subuh'], 'date' => '2026-11-14', 'entries' => []])->assertNotFound();
    $this->get(route('dormitory.attendance.index'))->assertForbidden();
    $this->get(route('dormitory.attendance.my-recap', ['room' => $this->room['A-102']]))->assertNotFound();

    $this->get(route('dormitory.attendance.my-recap', ['room' => $this->room['A-101']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dormitory/attendance-recap')->where('recap.month', '2026-11')
            ->has('recap.data.people', 4)->where('recap.data.rooms.0.code', 'A-101')->has('recap.data.rooms', 1));
    $this->get(route('dormitory.attendance.my-recap.export', ['room' => $this->room['A-101']]))->assertOk()->assertDownload('rekap-absen-asr-u1-a-101-2026-11.xlsx');
});

test('rekap bulanan: H/I/S/T per orang, % hadir, saring jenis, sesi diisi per kamar, unduhan; Kepala Asrama boleh lihat', function () {
    dmaSave($this, 'A-101', 'Subuh', '2026-11-15', ['Ahmad' => 'H', 'Budi' => 'S', 'Eko' => 'T', 'Hasan' => 'I'])->assertSessionHasNoErrors();
    dmaSave($this, 'A-101', 'Malam', '2026-11-15', ['Ahmad' => 'H', 'Budi' => 'H', 'Eko' => 'H', 'Hasan' => 'H'])->assertSessionHasNoErrors();
    // Kemarin: Eko masih di A-102 (penempatan lama).
    $this->post(route('dormitory.attendance.save', ['room' => $this->room['A-101']]), ['kind' => $this->kind['Subuh'], 'date' => '2026-11-14', 'entries' => [
        dmaPlacement($this, 'Ahmad') => ['status' => 'H'], dmaPlacement($this, 'Budi') => ['status' => 'H'], dmaPlacement($this, 'Hasan') => ['status' => 'H'],
    ]])->assertSessionHasNoErrors();
    $ekoOld = Placement::query()->where('membership_id', $this->person['Eko'])->where('status', 'SELESAI')->sole()->id;
    $this->post(route('dormitory.attendance.save', ['room' => $this->room['A-102']]), ['kind' => $this->kind['Subuh'], 'date' => '2026-11-14', 'entries' => [$ekoOld => ['status' => 'H']]])
        ->assertSessionHasNoErrors();

    $this->get(route('dormitory.attendance.index', ['asrama' => $this->dorm->id, 'tab' => 'rekap']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'rekap')->where('fill', null)
            ->where('recap.data.people', fn ($people) => collect($people)->map(fn ($row) => [$row['name'], $row['H'], $row['I'], $row['S'], $row['T'], $row['total'], $row['percent']])->all() === [
                ['Ahmad', 3, 0, 0, 0, 3, 100], ['Budi', 2, 0, 1, 0, 3, 66.7], ['Eko', 2, 0, 0, 1, 3, 66.7], ['Ustadz Hasan', 2, 1, 0, 0, 3, 66.7],
            ] && collect($people)->firstWhere('name', 'Eko')['rooms'] === 'A-101, A-102'
                && collect($people)->firstWhere('name', 'Ustadz Hasan')['category_label'] === 'Pengawas')
            ->where('recap.data.totals', fn ($totals) => $totals['H'] === 9 && $totals['total'] === 12 && $totals['percent'] == 75)
            ->where('recap.data.days', 15)->where('recap.data.kinds', 2)
            ->where('recap.data.rooms', fn ($rooms) => collect($rooms)->map(fn ($room) => [$room['code'], $room['filled'], $room['expected']])->all() === [['A-101', 3, 30], ['A-102', 1, 30]]));

    // Saring jenis Subuh + kamar A-102.
    $this->get(route('dormitory.attendance.index', ['asrama' => $this->dorm->id, 'tab' => 'rekap', 'jenis' => $this->kind['Subuh'], 'kamar' => $this->room['A-102']]))
        ->assertInertia(fn (Assert $page) => $page->has('recap.data.people', 1)->where('recap.data.people.0.name', 'Eko')
            ->where('recap.data.people.0.total', 1)->where('recap.data.rooms.0.expected', 15));

    $kepala = dmaUser($this, 'PONPES-U1', DormitoryAccess::ROLE_KEPALA_ASRAMA);
    $this->actingAs($kepala)->get(route('dormitory.attendance.index', ['tab' => 'rekap']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canFill', false)->has('recap.data.people', 4));
    $this->actingAs($kepala)->get(route('dormitory.attendance.sheet', ['room' => $this->room['A-101']]))->assertForbidden();

    $response = $this->actingAs($kepala)->get(route('dormitory.attendance.export', ['asrama' => $this->dorm->id, 'bulan' => '2026-11']));
    $response->assertOk()->assertDownload('rekap-absen-asr-u1-2026-11.xlsx');
    $reader = new XlsxReader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }
    }

    $reader->close();

    expect(array_keys($sheets))->toBe(['Rekap', 'Per kamar'])
        ->and($sheets['Rekap'][0][0])->toBe('Rekap absen Asrama Putra Unit 1 — November 2026 (semua jenis)')
        ->and($sheets['Rekap'][1])->toBe(['No', 'Nama', 'Kategori', 'Kamar', 'Hadir', 'Izin', 'Sakit', 'Tidak ada', 'Sesi tercatat', '% hadir'])
        ->and(array_slice($sheets['Rekap'][2], 1, 9))->toBe(['Ahmad', 'Santri', 'A-101', 3, 0, 0, 0, 3, 100])
        ->and($sheets['Rekap'][6][1])->toBe('Jumlah')
        ->and($sheets['Per kamar'][1])->toBe(['Gedung A', 'A-101', 3, 30]);

    // Pengurus unit lain tidak melihat asrama ini.
    $this->actingAs(dmaUser($this, 'PONPES-U2', DormitoryAccess::ROLE_PENGURUS_ASRAMA))
        ->get(route('dormitory.attendance.export', ['asrama' => $this->dorm->id]))->assertNotFound();
});
