<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Supervision\RoomSupervisor;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Wali kamar DM-3 (PRD-008 §17).
 *
 * Data uji (hari ini 15 Nov 2026): "Asrama Putra Unit 1" → Gedung A (putra) → kamar A-101 (Bed, Santri + pengawas,
 * 1 slot pengawas, 3 bed) dan A-102 (Bed, khusus santri, 2 bed). Pegawai putra: akun `guru` (dijadikan pegawai laki-laki),
 * Hasan, Rahman, Yusuf; pegawai putri: Fatimah. Santri: Ahmad (MA Unit 1).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->unit1 = CoreFixtures::node($this->tenant, 'PONPES-U1');
    $this->actingAs($this->admin);

    $this->post(route('dormitory.dormitories.store'), ['organization_id' => $this->unit1->id, 'code' => 'ASR-U1', 'name' => 'Asrama Putra Unit 1'])->assertSessionHasNoErrors();
    $this->dorm = Dormitory::query()->where('code', 'ASR-U1')->sole();
    $this->post(route('dormitory.buildings.store', ['dormitory' => $this->dorm->id]), ['code' => 'A', 'name' => 'Gedung A', 'gender' => 'L'])->assertSessionHasNoErrors();
    $this->building = Building::query()->sole();

    foreach ([['A-101', 'SANTRI_PENGAWAS', 3], ['A-102', 'KHUSUS_SANTRI', 2]] as [$code, $composition, $beds]) {
        $this->post(route('dormitory.rooms.store', ['building' => $this->building->id]), array_filter([
            'code' => $code, 'capacity_basis' => 'BED', 'composition' => $composition, 'supervisor_slots' => $composition === 'SANTRI_PENGAWAS' ? '1' : null,
        ]))->assertSessionHasNoErrors();
        $room = Room::query()->where('code', $code)->sole();
        $this->post(route('dormitory.resources.store', ['room' => $room->id]), ['kind' => 'bed', 'prefix' => 'B', 'start' => 1, 'count' => $beds])->assertSessionHasNoErrors();
    }

    $this->room = Room::query()->where('code', 'A-101')->sole();
    $this->room2 = Room::query()->where('code', 'A-102')->sole();

    $this->guru = DevAccounts::user('guru');
    $this->guruMembership = (string) Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->value('id');
    dmsEmployee($this, 'Ustadz Guru', Gender::Male, $this->guruMembership);
    // Nama Person akun `guru` yang sudah ada tidak ditimpa saat dijadikan pegawai.
    $this->guruName = (string) DB::table('persons')->where('id', $this->guru->person_id)->value('name');
    $this->hasan = dmsEmployee($this, 'Ustadz Hasan', Gender::Male);
    $this->rahman = dmsEmployee($this, 'Ustadz Rahman', Gender::Male);
    $this->yusuf = dmsEmployee($this, 'Ustadz Yusuf', Gender::Male);
    $this->fatimah = dmsEmployee($this, 'Ustadzah Fatimah', Gender::Female);

    $this->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => CoreFixtures::node($this->tenant, 'U1-MA')->id,
        'start_date' => '2026-07-13', 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();
    $this->ahmad = (string) DB::table('ac_students as s')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', 'Ahmad Fauzi')->value('s.membership_id');
});

function dmsEmployee(mixed $test, string $name, Gender $gender, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: $gender, birthPlace: null, birthDate: null, phone: null,
        address: null, employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: CoreFixtures::node($test->tenant, 'U1-MA')->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->membership_id;
}

function dmsAssign(mixed $test, string $membershipId, array $overrides = [], ?string $roomId = null): mixed
{
    return $test->post(route('dormitory.supervisors.store', ['room' => $roomId ?? $test->room->id]), array_merge([
        'membership_id' => $membershipId, 'date' => '2026-11-15',
    ], $overrides));
}

function dmsActive(string $membershipId, string $roomId): RoomSupervisor
{
    return RoomSupervisor::query()->where('membership_id', $membershipId)->where('room_id', $roomId)->whereNull('end_on')->sole();
}

/** Ganti pengguna: lembaga kerja di session milik pengguna sebelumnya, jadi session dikosongkan dulu. */
function dmsAs(mixed $test, User $user): mixed
{
    $test->flushSession();
    $test->actingAs($user);

    // Akun `guru` punya dua penugasan (MA & MDA Unit 1), jadi lembaga kerjanya dipilih dulu.
    if ($user->username === 'guru') {
        DevAccounts::chooseWorkspace($test, $user, 'U1-MA');
    }

    return $test;
}

function dmsUserWithRole(mixed $test, string $nodeCode, string $roleKey): User
{
    $user = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($user, $test->tenant), CoreFixtures::node($test->tenant, $nodeCode)), $roleKey);
    $test->flushSession();

    return $user;
}

test('tambah wali kamar: tercatat & tampil di kamar dan tab Wali kamar asrama; tinggal di kamar = badge', function () {
    dmsAssign($this, $this->hasan, ['date' => '2026-11-01', 'note' => 'Musyrif lantai 1'])->assertRedirect()->assertSessionHasNoErrors();

    $supervisor = dmsActive($this->hasan, $this->room->id);
    expect($supervisor->start_on->toDateString())->toBe('2026-11-01')
        ->and($supervisor->note)->toBe('Musyrif lantai 1')
        ->and(json_decode((string) DB::table('audit_logs')->where('action', DormitoryAuditAction::SUPERVISOR_ASSIGNED)->value('changes'), true))
        ->toEqual(['kamar' => 'A-101', 'wali_kamar' => 'Ustadz Hasan', 'mulai' => '2026-11-01']);

    // Mengawasi ≠ tinggal: tidak memakai kapasitas.
    $this->get(route('dormitory.rooms.show', ['room' => $this->room->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('supervisors', 1)
            ->where('supervisors.0.name', 'Ustadz Hasan')
            ->where('supervisors.0.lives_here', false)
            ->where('supervisors.0.employee_active', true)
            ->where('occupancy.active', 0));

    // Juga tinggal lewat Tempatkan → Pengawas (DM-2) → badge.
    $this->post(route('dormitory.placements.store', ['room' => $this->room->id]), ['membership_id' => $this->hasan, 'category' => 'PENGAWAS', 'mode' => 'langsung', 'date' => '2026-11-15'])
        ->assertSessionHasNoErrors();
    $this->get(route('dormitory.rooms.show', ['room' => $this->room->id]))
        ->assertInertia(fn (Assert $page) => $page->where('supervisors.0.lives_here', true));

    // Satu pegawai boleh mengawasi beberapa kamar, juga kamar khusus santri.
    dmsAssign($this, $this->hasan, [], $this->room2->id)->assertSessionHasNoErrors();

    $this->get(route('dormitory.dormitories.show', ['dormitory' => $this->dorm->id, 'tab' => 'wali-kamar']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('supervisors.'.$this->room->id.'.0.name', 'Ustadz Hasan')
            ->where('supervisors.'.$this->room2->id.'.0.name', 'Ustadz Hasan'));
    $this->get(route('dormitory.dormitories.show', ['dormitory' => $this->dorm->id]))
        ->assertInertia(fn (Assert $page) => $page->where('supervisors', null));
});

test('ditolak: beda jenis kelamin, bukan pegawai, jenis kelamin kosong, dobel, lebih dari 3, tanggal, kamar nonaktif', function () {
    dmsAssign($this, $this->fatimah)->assertSessionHasErrors(['membership_id' => 'Kamar A-101 untuk putra; wali kamarnya harus laki-laki.']);
    dmsAssign($this, $this->ahmad)->assertSessionHasErrors(['membership_id' => 'Ahmad Fauzi bukan pegawai aktif.']);

    DB::table('persons')->where('name', 'Ustadz Yusuf')->update(['gender' => null]);
    dmsAssign($this, $this->yusuf)->assertSessionHasErrors(['membership_id' => 'Jenis kelamin Ustadz Yusuf belum diisi. Lengkapi di data pegawai dulu.']);
    DB::table('persons')->where('name', 'Ustadz Yusuf')->update(['gender' => 'L']);

    dmsAssign($this, $this->hasan, ['date' => '2026-11-16'])->assertSessionHasErrors(['date' => 'Tanggal mulai tidak boleh di masa depan.']);
    dmsAssign($this, $this->hasan, ['date' => '2025-11-14'])->assertSessionHasErrors(['date' => 'Tanggal mulai paling jauh 1 tahun ke belakang.']);
    dmsAssign($this, $this->hasan, ['date' => '15/11/2026'])->assertSessionHasErrors(['date' => 'Format tanggal tidak dikenali.']);
    dmsAssign($this, $this->hasan, ['membership_id' => ''])->assertSessionHasErrors(['membership_id' => 'Pilih pegawai dulu.']);

    dmsAssign($this, $this->hasan)->assertSessionHasNoErrors();
    dmsAssign($this, $this->hasan)->assertSessionHasErrors(['membership_id' => 'Ustadz Hasan sudah menjadi wali kamar A-101.']);
    dmsAssign($this, $this->rahman)->assertSessionHasNoErrors();
    dmsAssign($this, $this->yusuf)->assertSessionHasNoErrors();
    dmsAssign($this, $this->guruMembership)->assertSessionHasErrors(['form' => 'Kamar A-101 sudah punya 3 wali kamar. Akhiri salah satu dulu.']);

    $this->post(route('dormitory.rooms.status', ['room' => $this->room2->id]), ['active' => '0'])->assertSessionHasNoErrors();
    expect(Room::query()->whereKey($this->room2->id)->value('is_active'))->toBeFalse();
    dmsAssign($this, $this->hasan, [], $this->room2->id)
        ->assertSessionHasErrors(['form' => 'Kamar A-102, gedungnya, atau asramanya nonaktif, jadi tidak bisa diberi wali kamar.']);
});

test('akhiri penugasan: tanggal diperiksa, masuk riwayat, slot terbuka lagi; tidak bisa diakhiri dua kali', function () {
    dmsAssign($this, $this->hasan, ['date' => '2026-11-01'])->assertSessionHasNoErrors();
    $supervisor = dmsActive($this->hasan, $this->room->id);
    $end = fn (array $data) => $this->post(route('dormitory.supervisors.end', ['supervisor' => $supervisor->id]), $data);

    $end(['date' => '2026-10-31'])->assertSessionHasErrors(['date' => 'Tanggal selesai tidak boleh sebelum tanggal mulai (01/11/2026).']);
    $end(['date' => '2026-11-16'])->assertSessionHasErrors(['date' => 'Tanggal selesai tidak boleh di masa depan.']);
    $end(['date' => ''])->assertSessionHasErrors(['date' => 'Isi tanggal selesai.']);
    $end(['date' => '2026-11-10', 'note' => 'Pindah tugas'])->assertRedirect()->assertSessionHasNoErrors();
    $end(['date' => '2026-11-10'])->assertSessionHasErrors(['form' => 'Penugasan ini sudah diakhiri.']);

    $supervisor->refresh();
    expect($supervisor->end_on?->toDateString())->toBe('2026-11-10')->and($supervisor->note)->toBe('Pindah tugas')
        ->and(DB::table('audit_logs')->where('action', DormitoryAuditAction::SUPERVISOR_ENDED)->exists())->toBeTrue();

    $this->get(route('dormitory.rooms.show', ['room' => $this->room->id]))
        ->assertInertia(fn (Assert $page) => $page->has('supervisors', 0)->where('supervisorHistory', null));
    $this->get(route('dormitory.rooms.show', ['room' => $this->room->id, 'tab' => 'riwayat']))
        ->assertInertia(fn (Assert $page) => $page->has('supervisorHistory', 1)->where('supervisorHistory.0.end_on', '2026-11-10'));

    // Boleh ditugaskan lagi di kamar yang sama.
    dmsAssign($this, $this->hasan)->assertSessionHasNoErrors();
});

test('Kamar saya: hanya untuk wali kamar aktif; berisi penghuni kamarnya; hilang saat pegawai berhenti', function () {
    dmsAs($this, $this->guru)->get(route('dormitory.my-rooms.index'))->assertNotFound();
    dmsAs($this, $this->guru)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('dormitory.myRooms', false));

    dmsAs($this, $this->admin);
    dmsAssign($this, $this->guruMembership)->assertSessionHasNoErrors();
    $this->post(route('dormitory.placements.store', ['room' => $this->room->id]), ['membership_id' => $this->ahmad, 'category' => 'PENGHUNI', 'mode' => 'langsung', 'date' => '2026-11-15'])
        ->assertSessionHasNoErrors();

    dmsAs($this, $this->guru)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('dormitory.myRooms', true));
    dmsAs($this, $this->guru)->get(route('dormitory.my-rooms.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dormitory/my-rooms')
            ->has('rooms', 1)
            ->where('rooms.0.room.code', 'A-101')
            ->where('rooms.0.holders.0.name', 'Ahmad Fauzi')
            ->where('rooms.0.supervisors.0.name', $this->guruName));

    // Penugasan tidak membuka halaman Asrama lain (tanpa permission di cakupan mana pun → 404).
    dmsAs($this, $this->guru)->get(route('dormitory.rooms.show', ['room' => $this->room->id]))->assertNotFound();

    // Pegawai berhenti → penugasan tetap (ditandai), Kamar saya hilang (§17.2 butir 6).
    DB::table('hr_employees')->where('membership_id', $this->guruMembership)->update(['status' => 'BERHENTI']);
    dmsAs($this, $this->guru)->get(route('dormitory.my-rooms.index'))->assertNotFound();
    dmsAs($this, $this->admin)->get(route('dormitory.rooms.show', ['room' => $this->room->id]))
        ->assertInertia(fn (Assert $page) => $page->where('supervisors.0.name', $this->guruName)->where('supervisors.0.employee_active', false));
    dmsAssign(dmsAs($this, $this->admin), $this->guruMembership, [], $this->room2->id)->assertSessionHasErrors(['membership_id' => $this->guruName.' bukan pegawai aktif.']);
});

test('hak akses: kepala asrama hanya melihat (403), pengurus unit lain 404, pencarian calon menandai yang tidak cocok', function () {
    $kepala = dmsUserWithRole($this, 'PONPES-U1', DormitoryAccess::ROLE_KEPALA_ASRAMA);
    $this->actingAs($kepala)->get(route('dormitory.rooms.show', ['room' => $this->room->id]))->assertOk();
    dmsAssign($this->actingAs($kepala), $this->hasan)->assertForbidden();
    $this->actingAs($kepala)->getJson(route('dormitory.supervisors.candidates', ['room' => $this->room->id, 'q' => 'Ustadz']))->assertForbidden();

    $pengurus2 = dmsUserWithRole($this, 'PONPES-U2', DormitoryAccess::ROLE_PENGURUS_ASRAMA);
    dmsAssign($this->actingAs($pengurus2), $this->hasan)->assertNotFound();

    $pengurus1 = dmsUserWithRole($this, 'PONPES-U1', DormitoryAccess::ROLE_PENGURUS_ASRAMA);
    dmsAssign($this->actingAs($pengurus1), $this->hasan)->assertRedirect()->assertSessionHasNoErrors();
    $supervisor = dmsActive($this->hasan, $this->room->id);
    $this->actingAs($pengurus2)->post(route('dormitory.supervisors.end', ['supervisor' => $supervisor->id]), ['date' => '2026-11-15'])->assertNotFound();
    $this->actingAs($kepala)->post(route('dormitory.supervisors.end', ['supervisor' => $supervisor->id]), ['date' => '2026-11-15'])->assertForbidden();

    $candidates = collect($this->actingAs($pengurus1)->getJson(route('dormitory.supervisors.candidates', ['room' => $this->room->id, 'q' => 'Ustadz']))
        ->assertOk()->json('candidates'))->keyBy('name');
    expect($candidates['Ustadzah Fatimah']['blocked'])->toBe('Perempuan')
        ->and($candidates['Ustadz Hasan']['blocked'])->toBe('Sudah menjadi wali kamar ini')
        ->and($candidates['Ustadz Rahman']['blocked'])->toBeNull();
    $this->actingAs($pengurus1)->getJson(route('dormitory.supervisors.candidates', ['room' => $this->room->id, 'q' => 'Us']))->assertJsonPath('candidates', []);
});

test('database: satu penugasan aktif per orang per kamar, tanggal selesai tidak sebelum mulai, ikut pemilik kamar', function () {
    dmsAssign($this, $this->hasan)->assertSessionHasNoErrors();

    $insert = fn (array $overrides) => DB::transaction(fn () => DB::table('dm_room_supervisors')->insert(array_merge([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => $this->unit1->id, 'dormitory_id' => $this->dorm->id,
        'room_id' => $this->room->id, 'membership_id' => $this->rahman, 'start_on' => '2026-11-15', 'created_at' => now(), 'updated_at' => now(),
    ], $overrides)));

    expect(fn () => $insert(['membership_id' => $this->hasan]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['end_on' => '2026-11-14']))->toThrow(QueryException::class)
        ->and(fn () => $insert(['organization_id' => CoreFixtures::node($this->tenant, 'PONPES-U2')->id]))->toThrow(QueryException::class);

    // Penugasan lama yang sudah selesai tidak menghalangi penugasan baru.
    $insert(['membership_id' => $this->hasan, 'start_on' => '2026-01-01', 'end_on' => '2026-06-30']);
    expect(RoomSupervisor::query()->where('membership_id', $this->hasan)->count())->toBe(2);
});
