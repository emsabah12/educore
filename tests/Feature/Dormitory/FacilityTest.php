<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Facility\RoomCapacity;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\CapacityBasis;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Fasilitas asrama DM-1 (PRD-008 §4, §7, §15).
 *
 * Data uji: Admin Yayasan membuat "Asrama Putra Unit 1" (pemilik Unit 1) dengan Gedung A (putra) dan kamar A-101
 * (dasar Bed + loker, Santri + pengawas, 1 slot pengawas), serta "Asrama Putri Unit 2" (pemilik Unit 2).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->unit1 = CoreFixtures::node($this->tenant, 'PONPES-U1');
    $this->unit2 = CoreFixtures::node($this->tenant, 'PONPES-U2');

    $this->actingAs($this->admin);

    $this->dormU1 = dmfDormitory($this, $this->unit1->id, 'ASR-U1', 'Asrama Putra Unit 1');
    $this->dormU2 = dmfDormitory($this, $this->unit2->id, 'ASR-U2', 'Asrama Putri Unit 2');

    $this->post(route('dormitory.buildings.store', ['dormitory' => $this->dormU1->id]), ['code' => 'a', 'name' => 'Gedung A', 'gender' => 'L'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->buildingA = Building::query()->where('dormitory_id', $this->dormU1->id)->sole();

    $this->post(route('dormitory.rooms.store', ['building' => $this->buildingA->id]), [
        'code' => ' a 101 ', 'capacity_basis' => 'BED_LOKER', 'composition' => 'SANTRI_PENGAWAS', 'supervisor_slots' => '1',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->room = Room::query()->where('building_id', $this->buildingA->id)->sole();
});

function dmfDormitory(mixed $test, string $organizationId, string $code, string $name): Dormitory
{
    $test->post(route('dormitory.dormitories.store'), ['organization_id' => $organizationId, 'code' => $code, 'name' => $name])
        ->assertRedirect()->assertSessionHasNoErrors();

    return Dormitory::query()->where('code', $code)->sole();
}

function dmfAdd(mixed $test, string $roomId, string $kind, string $prefix, int $start, int $count): mixed
{
    return $test->post(route('dormitory.resources.store', ['room' => $roomId]), ['kind' => $kind, 'prefix' => $prefix, 'start' => $start, 'count' => $count]);
}

/**
 * Akun baru dengan satu role penugasan di sebuah node.
 */
function dmfUserWithRole(mixed $test, string $organizationId, string $roleKey): User
{
    $user = User::factory()->create();
    $organization = CoreFixtures::node($test->tenant, (string) DB::table('organizations')->where('id', $organizationId)->value('code'));
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($user, $test->tenant), $organization), $roleKey);
    $test->flushSession();

    return $user;
}

// ── Kapasitas ─────────────────────────────────────────────────────────────────

test('kapasitas: dihitung dari bed/loker tersedia sesuai dasar kapasitas; slot pengawas bagian dari kapasitas', function () {
    expect(CapacityBasis::Bed->effectiveCapacity(20, 16))->toBe(20)
        ->and(CapacityBasis::Locker->effectiveCapacity(20, 16))->toBe(16)
        ->and(CapacityBasis::BedAndLocker->effectiveCapacity(20, 16))->toBe(16);

    dmfAdd($this, $this->room->id, 'bed', 'A-101-', 1, 20)->assertRedirect()->assertSessionHasNoErrors();
    dmfAdd($this, $this->room->id, 'loker', 'A-101-L', 1, 16)->assertRedirect()->assertSessionHasNoErrors();

    // 20 bed + 16 loker, dasar Bed + loker → 16 (bukan 36); 1 slot pengawas → 15 slot santri.
    expect(RoomCapacity::forRoom($this->room))->toBe([
        'beds_total' => 20, 'beds_available' => 20, 'lockers_total' => 16, 'lockers_available' => 16,
        'effective' => 16, 'supervisor_slots' => 1, 'resident_slots' => 15,
    ]);

    // Bed rusak & loker nonaktif tidak dihitung.
    $bed = DB::table('dm_beds')->where('code', 'A-101-01')->sole();
    $this->patch(route('dormitory.resources.update', ['kind' => 'bed', 'resource' => $bed->id]), ['code' => 'A-101-01', 'status' => 'TIDAK_TERSEDIA', 'unavailable_reason' => 'RUSAK'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $locker = DB::table('dm_lockers')->where('code', 'A-101-L01')->sole();
    $this->post(route('dormitory.resources.status', ['kind' => 'loker', 'resource' => $locker->id]), ['active' => '0'])->assertRedirect()->assertSessionHasNoErrors();

    $this->patch(route('dormitory.rooms.update', ['room' => $this->room->id]), ['code' => 'A-101', 'capacity_basis' => 'BED', 'composition' => 'SANTRI_PENGAWAS', 'supervisor_slots' => '1'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(RoomCapacity::forRoom($this->room->refresh())['effective'])->toBe(19);

    // Halaman asrama: ringkasan hanya kamar aktif; halaman kamar menampilkan rincian.
    $this->get(route('dormitory.dormitories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dormitory/index')
            ->has('dormitories', 2)
            ->where('dormitories.0.code', 'ASR-U1')
            ->where('dormitories.0.rooms', 1)
            ->where('dormitories.0.effective', 19)
            ->where('dormitories.0.resident_slots', 18)
            ->where('dormitories.0.can_manage', true),
        );

    $this->get(route('dormitory.rooms.show', ['room' => $this->room->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dormitory/room')
            ->where('room.code', 'A-101')
            ->where('room.building.gender_label', 'Putra')
            ->where('capacity.beds_available', 19)
            ->where('capacity.lockers_total', 15)
            ->has('beds', 20)
            ->has('lockers', 16)
            ->where('beds.0.status', 'TIDAK_TERSEDIA'),
        );
});

// ── Tambah & aturan ───────────────────────────────────────────────────────────

test('tambah: kode dirapikan, kamar ikut pemilik & kebijakan asrama, tercatat di audit', function () {
    expect($this->room->code)->toBe('A-101')
        ->and($this->room->organization_id)->toBe($this->unit1->id)
        ->and($this->room->dormitory_id)->toBe($this->dormU1->id)
        ->and($this->room->year_end_policy->value)->toBe('TETAP')
        ->and($this->buildingA->code)->toBe('A');

    dmfAdd($this, $this->room->id, 'bed', 'a 101 ', 8, 3)->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('dm_beds')->orderBy('code')->pluck('code')->all())->toBe(['A-10108', 'A-10109', 'A-10110']);

    expect(json_decode((string) DB::table('audit_logs')->where('action', DormitoryAuditAction::RESOURCES_ADDED)->sole()->changes, true))
        ->toEqual(['kamar' => 'A-101', 'jenis' => 'bed', 'jumlah' => 3, 'dari' => 'A-10108', 'sampai' => 'A-10110'])
        ->and(DB::table('audit_logs')->where('action', DormitoryAuditAction::DORMITORY_CREATED)->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', DormitoryAuditAction::ROOM_CREATED)->where('subject_id', $this->room->id)->exists())->toBeTrue();
});

test('ditolak: pemilik biro, kode dobel, slot pengawas, tambah banyak bentrok (semua-atau-tidak), alasan tidak tersedia', function () {
    $biro = CoreFixtures::node($this->tenant, 'BIRO-SDM-KEU');
    $this->post(route('dormitory.dormitories.store'), ['organization_id' => $biro->id, 'code' => 'ASR-B', 'name' => 'Asrama Biro'])
        ->assertSessionHasErrors(['organization_id' => 'Asrama hanya bisa dimiliki lembaga atau unit, bukan biro.']);
    $this->post(route('dormitory.dormitories.store'), ['organization_id' => $this->unit1->id, 'code' => 'asr-u2', 'name' => 'Lain'])
        ->assertSessionHasErrors(['code' => 'Kode ASR-U2 sudah dipakai asrama lain.']);
    $this->post(route('dormitory.dormitories.store'), ['organization_id' => $this->unit1->id, 'code' => 'ASR_X', 'name' => 'Lain'])
        ->assertSessionHasErrors(['code' => 'Kode hanya boleh berisi huruf, angka, dan tanda "-".']);

    $this->post(route('dormitory.rooms.store', ['building' => $this->buildingA->id]), ['code' => 'A-101', 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI'])
        ->assertSessionHasErrors(['code' => 'Kode A-101 sudah dipakai kamar lain di gedung ini.']);
    $this->post(route('dormitory.rooms.store', ['building' => $this->buildingA->id]), ['code' => 'A-102', 'capacity_basis' => 'BED', 'composition' => 'SANTRI_PENGAWAS', 'supervisor_slots' => '0'])
        ->assertSessionHasErrors(['supervisor_slots' => 'Kamar santri + pengawas wajib punya 1–5 slot pengawas.']);

    // Khusus santri: slot pengawas selalu 0 walau diisi.
    $this->post(route('dormitory.rooms.store', ['building' => $this->buildingA->id]), ['code' => 'A-102', 'capacity_basis' => 'LOKER', 'composition' => 'KHUSUS_SANTRI', 'supervisor_slots' => '3'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(Room::query()->where('code', 'A-102')->sole()->supervisor_slots)->toBe(0);

    // Slot pengawas harus menyisakan tempat santri bila kamar sudah berkapasitas.
    dmfAdd($this, $this->room->id, 'bed', 'B', 1, 2)->assertSessionHasNoErrors();
    dmfAdd($this, $this->room->id, 'loker', 'L', 1, 2)->assertSessionHasNoErrors();
    $this->patch(route('dormitory.rooms.update', ['room' => $this->room->id]), ['code' => 'A-101', 'capacity_basis' => 'BED_LOKER', 'composition' => 'SANTRI_PENGAWAS', 'supervisor_slots' => '2'])
        ->assertSessionHasErrors(['supervisor_slots' => 'Kapasitas kamar ini 2, jadi slot pengawas paling banyak 1 agar masih ada tempat untuk santri.']);

    // B01–B02 sudah ada: B01–B05 ditolak seluruhnya.
    dmfAdd($this, $this->room->id, 'bed', 'B', 1, 5)->assertSessionHasErrors(['prefix' => 'Kode B01, B02 sudah ada di kamar ini. Ganti awalan atau nomor mulai.']);
    expect(DB::table('dm_beds')->count())->toBe(2);
    dmfAdd($this, $this->room->id, 'bed', 'B', 1, 101)->assertSessionHasErrors('count');

    $bed = DB::table('dm_beds')->where('code', 'B01')->sole();
    $this->patch(route('dormitory.resources.update', ['kind' => 'bed', 'resource' => $bed->id]), ['code' => 'B01', 'status' => 'TIDAK_TERSEDIA'])
        ->assertSessionHasErrors(['unavailable_reason' => 'Pilih alasan kenapa tidak tersedia.']);
    $this->patch(route('dormitory.resources.update', ['kind' => 'bed', 'resource' => $bed->id]), ['code' => 'B01', 'status' => 'TIDAK_TERSEDIA', 'unavailable_reason' => 'LAINNYA'])
        ->assertSessionHasErrors(['unavailable_note' => 'Tuliskan keterangan untuk alasan Lainnya.']);
    $this->patch(route('dormitory.resources.update', ['kind' => 'bed', 'resource' => $bed->id]), ['code' => 'B02', 'status' => 'TERSEDIA'])
        ->assertSessionHasErrors(['code' => 'Kode B02 sudah ada di kamar ini.']);

    // Kembali tersedia → alasan & catatan dikosongkan.
    $this->patch(route('dormitory.resources.update', ['kind' => 'bed', 'resource' => $bed->id]), ['code' => 'B01', 'status' => 'TIDAK_TERSEDIA', 'unavailable_reason' => 'LAINNYA', 'unavailable_note' => 'Dipinjam kamar tamu'])
        ->assertSessionHasNoErrors();
    $this->patch(route('dormitory.resources.update', ['kind' => 'bed', 'resource' => $bed->id]), ['code' => 'B01', 'status' => 'TERSEDIA', 'unavailable_reason' => 'LAINNYA', 'unavailable_note' => 'x'])
        ->assertSessionHasNoErrors();
    $fresh = DB::table('dm_beds')->where('id', $bed->id)->sole();
    expect($fresh->status)->toBe('TERSEDIA')->and($fresh->unavailable_reason)->toBeNull()->and($fresh->unavailable_note)->toBeNull();
});

test('nonaktif berjenjang: induk nonaktif menolak anak baru & aktifkan kembali anak; hapus bed/loker', function () {
    $this->post(route('dormitory.buildings.status', ['building' => $this->buildingA->id]), ['active' => '0'])->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('dormitory.rooms.store', ['building' => $this->buildingA->id]), ['code' => 'A-103', 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI'])
        ->assertSessionHasErrors(['form' => 'Gedung atau asramanya nonaktif. Aktifkan dulu sebelum menambah kamar.']);
    dmfAdd($this, $this->room->id, 'bed', 'X', 1, 1)->assertSessionHasErrors(['form' => 'Kamar, gedung, atau asramanya nonaktif. Aktifkan dulu.']);

    $this->post(route('dormitory.dormitories.status', ['dormitory' => $this->dormU1->id]), ['active' => '0'])->assertSessionHasNoErrors();
    $this->post(route('dormitory.buildings.status', ['building' => $this->buildingA->id]), ['active' => '1'])
        ->assertSessionHasErrors(['form' => 'Asramanya nonaktif. Aktifkan asrama dulu.']);
    $this->post(route('dormitory.buildings.store', ['dormitory' => $this->dormU1->id]), ['code' => 'B', 'name' => 'Gedung B', 'gender' => 'L'])
        ->assertSessionHasErrors(['form' => 'Asrama Asrama Putra Unit 1 nonaktif. Aktifkan dulu sebelum menambah gedung.']);

    // Asrama nonaktif tidak tampil di daftar kecuali diminta.
    $this->get(route('dormitory.dormitories.index'))->assertInertia(fn (Assert $page) => $page->has('dormitories', 1));
    $this->get(route('dormitory.dormitories.index', ['status' => 'semua']))->assertInertia(fn (Assert $page) => $page->has('dormitories', 2));

    $this->post(route('dormitory.dormitories.status', ['dormitory' => $this->dormU1->id]), ['active' => '1'])->assertSessionHasNoErrors();
    $this->post(route('dormitory.buildings.status', ['building' => $this->buildingA->id]), ['active' => '1'])->assertSessionHasNoErrors();
    dmfAdd($this, $this->room->id, 'loker', 'L', 1, 1)->assertSessionHasNoErrors();

    $locker = DB::table('dm_lockers')->sole();
    $this->delete(route('dormitory.resources.destroy', ['kind' => 'loker', 'resource' => $locker->id]))->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('dm_lockers')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', DormitoryAuditAction::RESOURCE_DELETED)->count())->toBe(1);
});

// ── Hak akses ─────────────────────────────────────────────────────────────────

test('hak akses: pengurus hanya asrama unitnya; kepala asrama & pimpinan hanya melihat; tanpa izin 403', function () {
    $pengurus = dmfUserWithRole($this, $this->unit1->id, DormitoryAccess::ROLE_PENGURUS_ASRAMA);

    $this->actingAs($pengurus)->get(route('dormitory.dormitories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('dormitories', 1)->where('dormitories.0.code', 'ASR-U1')->has('options.createOwners'));
    $this->actingAs($pengurus)->get(route('dormitory.dormitories.show', ['dormitory' => $this->dormU2->id]))->assertNotFound();
    $this->actingAs($pengurus)->post(route('dormitory.dormitories.store'), ['organization_id' => $this->unit2->id, 'code' => 'X', 'name' => 'X'])->assertNotFound();
    $this->actingAs($pengurus)->post(route('dormitory.buildings.store', ['dormitory' => $this->dormU1->id]), ['code' => 'C', 'name' => 'Gedung C', 'gender' => 'L'])
        ->assertRedirect()->assertSessionHasNoErrors();
    dmfAdd($this->actingAs($pengurus), $this->room->id, 'bed', 'P', 1, 2)->assertSessionHasNoErrors();

    $kepala = dmfUserWithRole($this, $this->unit1->id, DormitoryAccess::ROLE_KEPALA_ASRAMA);
    $this->actingAs($kepala)->get(route('dormitory.rooms.show', ['room' => $this->room->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->actingAs($kepala)->patch(route('dormitory.rooms.update', ['room' => $this->room->id]), ['code' => 'Z', 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI'])->assertForbidden();

    $pimpinan = DevAccounts::user('pimpinan.ponpes');
    $this->flushSession();
    $this->actingAs($pimpinan)->get(route('dormitory.dormitories.index'))
        ->assertInertia(fn (Assert $page) => $page->has('dormitories', 2)->where('dormitories.0.can_manage', false)->where('options.createOwners', []));
    $this->actingAs($pimpinan)->post(route('dormitory.dormitories.status', ['dormitory' => $this->dormU2->id]), ['active' => '0'])->assertForbidden();

    // Kepala Unit 1 melihat asrama Unit 1 saja.
    $this->flushSession();
    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('dormitory.dormitories.index'))
        ->assertInertia(fn (Assert $page) => $page->has('dormitories', 1));

    $guru = DevAccounts::user('guru');
    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('dormitory.dormitories.index'))->assertForbidden();
});

// ── Database ──────────────────────────────────────────────────────────────────

test('database menjaga pemilik berantai, komposisi, status bed, dan format kode', function () {
    $insertBuilding = fn (string $organizationId, string $code) => DB::table('dm_buildings')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => $organizationId, 'dormitory_id' => $this->dormU1->id,
        'code' => $code, 'name' => 'Gedung', 'gender' => 'L', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Gedung asrama Unit 1 tidak bisa diaku milik Unit 2.
    expect(fn () => DB::transaction(fn () => $insertBuilding($this->unit2->id, 'Z')))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => $insertBuilding($this->unit1->id, 'a-1')))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('dm_rooms')->where('id', $this->room->id)->update(['composition' => 'KHUSUS_SANTRI'])))
        ->toThrow(QueryException::class);

    dmfAdd($this, $this->room->id, 'bed', 'B', 1, 1)->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('dm_beds')->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('dm_beds')->update(['status' => 'TIDAK_TERSEDIA'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('dm_beds')->update(['unavailable_reason' => 'RUSAK'])))->toThrow(QueryException::class);
});
