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
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Placement\Placement;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Penempatan penghuni DM-2 (PRD-008 §16).
 *
 * Data uji (hari ini 15 Nov 2026): "Asrama Putra Unit 1" (pemilik Unit 1) → Gedung A (putra) → kamar A-101
 * (Bed + loker, Santri + pengawas, 1 slot pengawas) dengan 3 bed + 3 loker → kapasitas 3 = 2 santri + 1 pengawas.
 * Santri: Ahmad (MA Unit 1), Budi (MTs Unit 1), Eko (MA Unit 1), Citra (putri, MA Unit 1), Dodi (MA Unit 2).
 * Pegawai: Ustadz Hasan (putra).
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
    $this->unit2 = CoreFixtures::node($this->tenant, 'PONPES-U2');
    $this->actingAs($this->admin);

    $this->post(route('dormitory.dormitories.store'), ['organization_id' => $this->unit1->id, 'code' => 'ASR-U1', 'name' => 'Asrama Putra Unit 1'])->assertSessionHasNoErrors();
    $this->dorm = Dormitory::query()->where('code', 'ASR-U1')->sole();
    $this->post(route('dormitory.buildings.store', ['dormitory' => $this->dorm->id]), ['code' => 'A', 'name' => 'Gedung A', 'gender' => 'L'])->assertSessionHasNoErrors();
    $this->building = Building::query()->sole();
    $this->post(route('dormitory.rooms.store', ['building' => $this->building->id]), [
        'code' => 'A-101', 'capacity_basis' => 'BED_LOKER', 'composition' => 'SANTRI_PENGAWAS', 'supervisor_slots' => '1',
    ])->assertSessionHasNoErrors();
    $this->room = Room::query()->where('code', 'A-101')->sole();
    $this->post(route('dormitory.resources.store', ['room' => $this->room->id]), ['kind' => 'bed', 'prefix' => 'B', 'start' => 1, 'count' => 3])->assertSessionHasNoErrors();
    $this->post(route('dormitory.resources.store', ['room' => $this->room->id]), ['kind' => 'loker', 'prefix' => 'L', 'start' => 1, 'count' => 3])->assertSessionHasNoErrors();

    $this->ahmad = dmpStudent($this, 'Ahmad Fauzi', 'L', 'U1-MA');
    $this->budi = dmpStudent($this, 'Budi Santoso', 'L', 'U1-MTS');
    $this->eko = dmpStudent($this, 'Eko Prasetyo', 'L', 'U1-MA');
    $this->citra = dmpStudent($this, 'Citra Lestari', 'P', 'U1-MA');
    $this->dodi = dmpStudent($this, 'Dodi Kurnia', 'L', 'U2-MA');
    $this->hasan = dmpEmployee($this, 'Ustadz Hasan', Gender::Male);
});

/** Siswa baru lewat form Tambah siswa; mengembalikan membership_id. */
function dmpStudent(mixed $test, string $name, string $gender, string $organizationCode): string
{
    $test->post(route('academic.students.store'), [
        'name' => $name, 'gender' => $gender, 'organization_id' => CoreFixtures::node($test->tenant, $organizationCode)->id,
        'start_date' => '2026-07-13', 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();

    return (string) DB::table('ac_students as s')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->value('s.membership_id');
}

function dmpEmployee(mixed $test, string $name, Gender $gender): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: null, name: $name, gender: $gender, birthPlace: null, birthDate: null, phone: null,
        address: null, employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: CoreFixtures::node($test->tenant, 'U1-MA')->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->membership_id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dmpPlace(mixed $test, string $membershipId, array $overrides = [], ?string $roomId = null): mixed
{
    return $test->post(route('dormitory.placements.store', ['room' => $roomId ?? $test->room->id]), array_merge([
        'membership_id' => $membershipId, 'category' => 'PENGHUNI', 'mode' => 'langsung', 'date' => '2026-11-15',
    ], $overrides));
}

function dmpOf(string $membershipId, string $status): Placement
{
    return Placement::query()->where('membership_id', $membershipId)->where('status', $status)->sole();
}

function dmpCode(string $table, ?string $id): ?string
{
    return $id === null ? null : (string) DB::table($table)->where('id', $id)->value('code');
}

function dmpUserWithRole(mixed $test, string $nodeCode, string $roleKey): User
{
    $user = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($user, $test->tenant), CoreFixtures::node($test->tenant, $nodeCode)), $roleKey);
    $test->flushSession();

    return $user;
}

// ── Tempatkan & aturan ────────────────────────────────────────────────────────

test('tempatkan langsung: bed & loker disarankan otomatis, tercatat, tampil di halaman kamar', function () {
    dmpPlace($this, $this->ahmad)->assertRedirect()->assertSessionHasNoErrors();

    $placement = dmpOf($this->ahmad, 'AKTIF');
    expect(dmpCode('dm_beds', $placement->bed_id))->toBe('B01')
        ->and(dmpCode('dm_lockers', $placement->locker_id))->toBe('L01')
        ->and($placement->check_in_on?->toDateString())->toBe('2026-11-15')
        ->and($placement->created_by)->toBe($placement->checked_in_by);

    // Bed dipilih petugas.
    $b03 = (string) DB::table('dm_beds')->where('code', 'B03')->value('id');
    dmpPlace($this, $this->budi, ['bed_id' => $b03])->assertSessionHasNoErrors();
    expect(dmpCode('dm_beds', dmpOf($this->budi, 'AKTIF')->bed_id))->toBe('B03')
        ->and(dmpCode('dm_lockers', dmpOf($this->budi, 'AKTIF')->locker_id))->toBe('L02');

    expect(json_decode((string) DB::table('audit_logs')->where('action', DormitoryAuditAction::PLACEMENT_CHECKED_IN)->where('subject_id', $placement->id)->value('changes'), true))
        ->toEqual(['kamar' => 'A-101', 'kategori' => 'PENGHUNI', 'bed' => 'B01', 'loker' => 'L01', 'tanggal' => '2026-11-15', 'langsung' => true]);

    $this->get(route('dormitory.rooms.show', ['room' => $this->room->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dormitory/room')
            ->has('holders', 2)
            ->where('holders.0.name', 'Ahmad Fauzi')
            ->where('holders.0.detail', 'MA Unit 1')
            ->where('occupancy.residents', 2)
            ->where('over_capacity', false)
            ->where('resourceHolders.beds.'.$placement->bed_id.'.name', 'Ahmad Fauzi'),
        );
});

test('ditolak: putri di gedung putra, lembaga unit lain, jenis kelamin kosong, slot santri & pengawas penuh, pengawas di kamar khusus santri', function () {
    dmpPlace($this, $this->citra)->assertSessionHasErrors(['membership_id' => 'Kamar A-101 untuk putra, sedangkan Citra Lestari putri.']);
    dmpPlace($this, $this->dodi)->assertSessionHasErrors(['membership_id' => 'Dodi Kurnia tidak punya pendaftaran aktif di lembaga milik Unit 1.']);
    dmpPlace($this, $this->hasan)->assertSessionHasErrors(['membership_id' => 'Ustadz Hasan tidak punya pendaftaran aktif di lembaga milik Unit 1.']);

    DB::table('persons')->where('name', 'Eko Prasetyo')->update(['gender' => null]);
    dmpPlace($this, $this->eko)->assertSessionHasErrors(['membership_id' => 'Jenis kelamin Eko Prasetyo belum diisi. Lengkapi di data siswa/pegawai dulu.']);
    DB::table('persons')->where('name', 'Eko Prasetyo')->update(['gender' => 'L']);

    dmpPlace($this, $this->ahmad)->assertSessionHasNoErrors();
    dmpPlace($this, $this->budi)->assertSessionHasNoErrors();
    dmpPlace($this, $this->eko)->assertSessionHasErrors(['form' => 'Kamar A-101 penuh untuk santri (2 dari 2).']);
    dmpPlace($this, $this->ahmad)->assertSessionHasErrors(['membership_id' => 'Ahmad Fauzi sudah tinggal di kamar A-101 (Asrama Putra Unit 1).']);

    dmpPlace($this, $this->hasan, ['category' => 'PENGAWAS'])->assertSessionHasNoErrors();
    expect(dmpOf($this->hasan, 'AKTIF')->category->value)->toBe('PENGAWAS');
    $hasan2 = dmpEmployee($this, 'Ustadz Rahman', Gender::Male);
    dmpPlace($this, $hasan2, ['category' => 'PENGAWAS'])->assertSessionHasErrors(['form' => 'Slot pengawas kamar A-101 sudah penuh (1 dari 1).']);

    $this->post(route('dormitory.rooms.store', ['building' => $this->building->id]), ['code' => 'A-102', 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI'])->assertSessionHasNoErrors();
    $a102 = Room::query()->where('code', 'A-102')->sole();
    $this->post(route('dormitory.resources.store', ['room' => $a102->id]), ['kind' => 'bed', 'prefix' => 'C', 'start' => 1, 'count' => 2])->assertSessionHasNoErrors();
    dmpPlace($this, $hasan2, ['category' => 'PENGAWAS'], $a102->id)->assertSessionHasErrors(['membership_id' => 'Kamar A-102 khusus santri; pengawas hanya bisa tinggal di kamar santri + pengawas.']);
    // Dasar Bed: loker tidak wajib.
    dmpPlace($this, $this->eko, [], $a102->id)->assertSessionHasNoErrors();
    expect(dmpOf($this->eko, 'AKTIF')->locker_id)->toBeNull();
});

test('rencana memesan bed; boleh untuk penghuni aktif kamar lain; check-in mengulang aturan & menunggu check-out', function () {
    $this->post(route('dormitory.rooms.store', ['building' => $this->building->id]), ['code' => 'A-102', 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI'])->assertSessionHasNoErrors();
    $a102 = Room::query()->where('code', 'A-102')->sole();
    $this->post(route('dormitory.resources.store', ['room' => $a102->id]), ['kind' => 'bed', 'prefix' => 'C', 'start' => 1, 'count' => 1])->assertSessionHasNoErrors();

    dmpPlace($this, $this->ahmad, [], $a102->id)->assertSessionHasNoErrors();
    // Rencana kamar tahun depan untuk Ahmad yang masih tinggal di A-102 (keputusan owner 2026-11-15).
    dmpPlace($this, $this->ahmad, ['mode' => 'rencana', 'date' => '2027-07-13'])->assertRedirect()->assertSessionHasNoErrors();
    $plan = dmpOf($this->ahmad, 'DIRENCANAKAN');
    expect($plan->planned_on?->toDateString())->toBe('2027-07-13')->and(dmpCode('dm_beds', $plan->bed_id))->toBe('B01');

    // Bed pesanan tidak bisa diambil orang lain; rencana ikut menghabiskan slot santri.
    dmpPlace($this, $this->budi, ['bed_id' => $plan->bed_id])->assertSessionHasErrors(['bed_id' => 'Bed B01 sudah dipakai atau dipesan penghuni lain.']);
    dmpPlace($this, $this->budi, ['mode' => 'rencana'])->assertSessionHasNoErrors();
    dmpPlace($this, $this->eko)->assertSessionHasErrors(['form' => 'Kamar A-101 penuh untuk santri (2 dari 2).']);
    dmpPlace($this, $this->ahmad, ['mode' => 'rencana'])->assertSessionHasErrors(['membership_id' => 'Ahmad Fauzi sudah punya rencana di kamar A-101 (Asrama Putra Unit 1). Batalkan dulu rencana itu.']);

    // Check-in ditolak selama masih tinggal di kamar lain.
    $this->post(route('dormitory.placements.check-in', ['placement' => $plan->id]), ['date' => '2026-11-15', 'bed_id' => $plan->bed_id, 'locker_id' => $plan->locker_id])
        ->assertSessionHasErrors(['form' => 'Ahmad Fauzi masih tinggal di kamar A-102 (Asrama Putra Unit 1). Check-out dulu dari kamar itu (pindah langsung = mutasi kamar).']);

    $this->post(route('dormitory.placements.check-out', ['placement' => dmpOf($this->ahmad, 'AKTIF')->id]), ['date' => '2026-11-15', 'end_reason' => 'KELUAR_ASRAMA'])
        ->assertRedirect()->assertSessionHasNoErrors();

    // Batalkan rencana Budi: wajib alasan; slot & bed pesanannya lepas.
    $budiPlan = dmpOf($this->budi, 'DIRENCANAKAN');
    $this->post(route('dormitory.placements.cancel', ['placement' => $budiPlan->id]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('dormitory.placements.cancel', ['placement' => $budiPlan->id]), ['reason' => 'Tidak jadi mondok'])->assertSessionHasNoErrors();
    expect(dmpOf($this->budi, 'DIBATALKAN')->cancel_reason)->toBe('Tidak jadi mondok');

    // Bed pesanan rusak → pilih yang lain (kosong = otomatis).
    $this->patch(route('dormitory.resources.update', ['kind' => 'bed', 'resource' => $plan->bed_id]), ['code' => 'B01', 'status' => 'TIDAK_TERSEDIA', 'unavailable_reason' => 'RUSAK'])->assertSessionHasNoErrors();
    $this->post(route('dormitory.placements.check-in', ['placement' => $plan->id]), ['date' => '2026-11-15', 'bed_id' => $plan->bed_id, 'locker_id' => $plan->locker_id])
        ->assertSessionHasErrors(['bed_id' => 'Bed B01 sedang rusak.']);
    $this->post(route('dormitory.placements.check-in', ['placement' => $plan->id]), ['date' => '2026-11-15', 'bed_id' => '', 'locker_id' => $plan->locker_id])
        ->assertRedirect()->assertSessionHasNoErrors();

    $plan->refresh();
    expect($plan->status->value)->toBe('AKTIF')->and(dmpCode('dm_beds', $plan->bed_id))->not->toBe('B01');

    // Kapasitas kini 2 (B01 rusak): 1 santri (Ahmad) + 1 pengawas.
    dmpPlace($this, $this->eko)->assertSessionHasErrors(['form' => 'Kamar A-101 penuh untuk santri (1 dari 1).']);
});

test('tanggal: tidak di masa depan, maks. 1 tahun ke belakang, tidak tumpang tindih; check-out melepas bed & alasan Lainnya wajib keterangan', function () {
    dmpPlace($this, $this->ahmad, ['date' => '2026-11-16'])->assertSessionHasErrors(['date' => 'Tanggal masuk tidak boleh di masa depan.']);
    dmpPlace($this, $this->ahmad, ['date' => '2025-11-14'])->assertSessionHasErrors(['date' => 'Tanggal masuk paling jauh 1 tahun ke belakang.']);
    dmpPlace($this, $this->ahmad, ['date' => '15-11-2026'])->assertSessionHasErrors(['date' => 'Format tanggal tidak dikenali.']);
    dmpPlace($this, $this->ahmad, ['date' => '2026-08-01'])->assertSessionHasNoErrors();

    $active = dmpOf($this->ahmad, 'AKTIF');
    $this->post(route('dormitory.placements.check-out', ['placement' => $active->id]), ['date' => '2026-07-31', 'end_reason' => 'KELUAR_ASRAMA'])
        ->assertSessionHasErrors(['date' => 'Tanggal keluar tidak boleh sebelum tanggal masuk (01/08/2026).']);
    $this->post(route('dormitory.placements.check-out', ['placement' => $active->id]), ['date' => '2026-11-10', 'end_reason' => 'LAINNYA'])
        ->assertSessionHasErrors(['note' => 'Tuliskan keterangan untuk alasan Lainnya.']);
    $this->post(route('dormitory.placements.check-out', ['placement' => $active->id]), ['date' => '2026-11-10', 'end_reason' => 'MUTASI'])
        ->assertSessionHasErrors(['end_reason' => 'Pilih alasan keluar.']);
    $this->post(route('dormitory.placements.check-out', ['placement' => $active->id]), ['date' => '2026-11-10', 'end_reason' => 'LAINNYA', 'note' => 'Pulang merawat orang tua'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $ended = $active->refresh();
    expect($ended->status->value)->toBe('SELESAI')->and($ended->end_reason?->value)->toBe('LAINNYA')->and($ended->note)->toBe('Pulang merawat orang tua');

    // Bed B01 kosong lagi; masuk kembali tidak boleh sebelum tanggal keluar terakhir.
    dmpPlace($this, $this->ahmad, ['date' => '2026-11-09'])->assertSessionHasErrors(['date' => 'Tanggal masuk tidak boleh sebelum tanggal keluar terakhirnya (10/11/2026).']);
    dmpPlace($this, $this->budi)->assertSessionHasNoErrors();
    expect(dmpCode('dm_beds', dmpOf($this->budi, 'AKTIF')->bed_id))->toBe('B01');

    $this->get(route('dormitory.rooms.show', ['room' => $this->room->id, 'tab' => 'riwayat']))
        ->assertInertia(fn (Assert $page) => $page->where('history.total', 1)->where('history.data.0.end_reason_label', 'Lainnya'));
});

test('kelebihan muatan menolak penempatan; pengaman fasilitas DM-1 saat kamar berpenghuni', function () {
    dmpPlace($this, $this->ahmad)->assertSessionHasNoErrors();
    dmpPlace($this, $this->hasan, ['category' => 'PENGAWAS'])->assertSessionHasNoErrors();
    $held = dmpOf($this->ahmad, 'AKTIF');

    // Bed yang dipegang Ahmad rusak → kelebihan muatan; kapasitas 2 sudah terisi 2.
    $this->patch(route('dormitory.resources.update', ['kind' => 'bed', 'resource' => $held->bed_id]), ['code' => 'B01', 'status' => 'TIDAK_TERSEDIA', 'unavailable_reason' => 'RUSAK'])->assertSessionHasNoErrors();
    $this->get(route('dormitory.rooms.show', ['room' => $this->room->id]))
        ->assertInertia(fn (Assert $page) => $page->where('over_capacity', true)->where('holders.0.resource_problem', true));
    dmpPlace($this, $this->budi)->assertSessionHasErrors(['form' => 'Kamar A-101 penuh untuk santri (1 dari 1).']);

    // Ganti bed Ahmad ke bed lain (bukan mutasi).
    $b03 = (string) DB::table('dm_beds')->where('code', 'B03')->value('id');
    $this->post(route('dormitory.placements.resources', ['placement' => $held->id]), ['bed_id' => $b03, 'locker_id' => $held->locker_id])->assertSessionHasNoErrors();
    expect(dmpCode('dm_beds', $held->refresh()->bed_id))->toBe('B03')
        ->and(DB::table('audit_logs')->where('action', DormitoryAuditAction::PLACEMENT_RESOURCE_CHANGED)->exists())->toBeTrue();

    $this->patch(route('dormitory.buildings.update', ['building' => $this->building->id]), ['code' => 'A', 'name' => 'Gedung A', 'gender' => 'P'])
        ->assertSessionHasErrors(['gender' => 'Gedung ini masih punya penghuni atau rencana penempatan. Kosongkan dulu sebelum mengganti putra/putri.']);
    $this->patch(route('dormitory.rooms.update', ['room' => $this->room->id]), ['code' => 'A-101', 'capacity_basis' => 'BED_LOKER', 'composition' => 'KHUSUS_SANTRI'])
        ->assertSessionHasErrors(['composition' => 'Masih ada pengawas yang tinggal/direncanakan di kamar ini. Check-out atau batalkan dulu sebelum menjadikannya khusus santri.']);
    $this->delete(route('dormitory.resources.destroy', ['kind' => 'bed', 'resource' => $b03]))
        ->assertSessionHasErrors(['form' => 'Bed B03 pernah dipakai penghuni, jadi tidak bisa dihapus. Nonaktifkan saja.']);

    // Kamar dasar Bed tanpa loker → ganti ke Bed + loker ditolak selama penghuninya belum memegang loker.
    $this->post(route('dormitory.rooms.store', ['building' => $this->building->id]), ['code' => 'A-102', 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI'])->assertSessionHasNoErrors();
    $a102 = Room::query()->where('code', 'A-102')->sole();
    $this->post(route('dormitory.resources.store', ['room' => $a102->id]), ['kind' => 'bed', 'prefix' => 'C', 'start' => 1, 'count' => 2])->assertSessionHasNoErrors();
    dmpPlace($this, $this->eko, [], $a102->id)->assertSessionHasNoErrors();
    $this->patch(route('dormitory.rooms.update', ['room' => $a102->id]), ['code' => 'A-102', 'capacity_basis' => 'BED_LOKER', 'composition' => 'KHUSUS_SANTRI'])
        ->assertSessionHasErrors(['capacity_basis' => 'Masih ada penghuni yang belum memegang loker. Lengkapi bed/lokernya dulu sebelum mengganti dasar kapasitas.']);
});

// ── Hak akses & halaman ───────────────────────────────────────────────────────

test('hak akses: kepala asrama hanya melihat; pengurus unit lain 404; daftar & riwayat penghuni sesuai cakupan', function () {
    dmpPlace($this, $this->ahmad)->assertSessionHasNoErrors();

    $kepala = dmpUserWithRole($this, 'PONPES-U1', DormitoryAccess::ROLE_KEPALA_ASRAMA);
    $this->actingAs($kepala)->get(route('dormitory.residents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dormitory/residents')->where('residents.total', 1)->where('residents.data.0.room', 'A-101'));
    $this->actingAs($kepala)->get(route('dormitory.residents.person', ['membership' => $this->ahmad]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dormitory/resident-history')->where('name', 'Ahmad Fauzi')->has('rows', 1));
    dmpPlace($this->actingAs($kepala), $this->budi)->assertForbidden();
    $this->actingAs($kepala)->get(route('dormitory.placements.candidates', ['room' => $this->room->id, 'q' => 'bud']))->assertForbidden();

    $pengurus2 = dmpUserWithRole($this, 'PONPES-U2', DormitoryAccess::ROLE_PENGURUS_ASRAMA);
    dmpPlace($this->actingAs($pengurus2), $this->budi)->assertNotFound();
    $this->actingAs($pengurus2)->get(route('dormitory.residents.index'))->assertInertia(fn (Assert $page) => $page->where('residents.total', 0));
    $this->actingAs($pengurus2)->get(route('dormitory.residents.person', ['membership' => $this->ahmad]))->assertNotFound();

    // Pencarian calon: yang tidak cocok tetap tampil dengan alasannya.
    $pengurus1 = dmpUserWithRole($this, 'PONPES-U1', DormitoryAccess::ROLE_PENGURUS_ASRAMA);
    $this->actingAs($pengurus1)->getJson(route('dormitory.placements.candidates', ['room' => $this->room->id, 'q' => 'a', 'kategori' => 'PENGHUNI']))
        ->assertOk()->assertJsonPath('candidates', []);
    $candidates = $this->actingAs($pengurus1)->getJson(route('dormitory.placements.candidates', ['room' => $this->room->id, 'q' => 'Lestari', 'kategori' => 'PENGHUNI']))
        ->assertOk()->json('candidates');
    expect($candidates)->toHaveCount(1)->and($candidates[0]['blocked'])->toBe('Putri');
    $ahmad = $this->actingAs($pengurus1)->getJson(route('dormitory.placements.candidates', ['room' => $this->room->id, 'q' => 'Ahmad']))->json('candidates.0');
    expect($ahmad['active_in'])->toBe('A-101')->and($ahmad['blocked'])->toBeNull();
    dmpPlace($this->actingAs($pengurus1), $this->budi)->assertRedirect()->assertSessionHasNoErrors();

    // Detail asrama: tab Penghuni.
    $this->actingAs($pengurus1)->get(route('dormitory.dormitories.show', ['dormitory' => $this->dorm->id, 'tab' => 'penghuni']))
        ->assertInertia(fn (Assert $page) => $page->where('residents.total', 2)->where('buildings.0.rooms.0.occupancy.active', 2));
});

test('database: satu penempatan aktif per orang, satu pemegang per bed, bed harus milik kamar, status konsisten', function () {
    dmpPlace($this, $this->ahmad)->assertSessionHasNoErrors();
    $held = dmpOf($this->ahmad, 'AKTIF');

    $insert = fn (array $overrides) => DB::transaction(fn () => DB::table('dm_placements')->insert(array_merge([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => $this->unit1->id, 'dormitory_id' => $this->dorm->id,
        'room_id' => $this->room->id, 'membership_id' => $this->budi, 'category' => 'PENGHUNI', 'status' => 'AKTIF', 'check_in_on' => '2026-11-15',
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides)));

    expect(fn () => $insert(['membership_id' => $this->ahmad]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['bed_id' => $held->bed_id]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['status' => 'SELESAI']))->toThrow(QueryException::class)
        ->and(fn () => $insert(['status' => 'DIBATALKAN', 'check_in_on' => null]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['category' => 'TAMU']))->toThrow(QueryException::class);

    // Bed kamar lain tidak bisa dipegang lewat kamar ini.
    $this->post(route('dormitory.rooms.store', ['building' => $this->building->id]), ['code' => 'A-102', 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI'])->assertSessionHasNoErrors();
    $a102 = Room::query()->where('code', 'A-102')->sole();
    $this->post(route('dormitory.resources.store', ['room' => $a102->id]), ['kind' => 'bed', 'prefix' => 'C', 'start' => 1, 'count' => 1])->assertSessionHasNoErrors();
    $otherBed = (string) DB::table('dm_beds')->where('room_id', $a102->id)->value('id');
    expect(fn () => $insert(['bed_id' => $otherBed]))->toThrow(QueryException::class);
    dmpPlace($this, $this->budi, ['bed_id' => $otherBed])->assertSessionHasErrors(['bed_id' => 'Bed tidak ditemukan di kamar A-101.']);
});
