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
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Mutation\Mutation;
use Modules\Dormitory\Domain\Placement\Placement;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Mutasi kamar DM-4 (PRD-008 §18).
 *
 * Data uji (hari ini 15 Nov 2026):
 * - "Asrama Putra Unit 1" (pemilik Unit 1): Gedung A (putra) → A-101 (2 bed), A-102 (1 bed); Gedung P (putri) → P-101.
 * - "Asrama Putra Unit 1 Timur" (pemilik Unit 1): Gedung C (putra) → C-101 (2 bed)   ← lintas asrama, pemilik sama.
 * - "Asrama Putra Unit 2" (pemilik Unit 2): Gedung D (putra) → D-101 (2 bed).
 * Santri Unit 1 (putra): Ahmad & Eko tinggal di A-101; Budi belum ditempatkan. Akun `guru` (pegawai) wali kamar A-101.
 * Petugas: Pengurus Asrama Unit 1 & Unit 2, Kepala Asrama Unit 1, Admin Yayasan.
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

    $this->room = [];
    $setup = [
        ['ASR-U1', 'Asrama Putra Unit 1', 'PONPES-U1', [['A', 'L', [['A-101', 2], ['A-102', 1]]], ['P', 'P', [['P-101', 2]]]]],
        ['ASR-U1T', 'Asrama Putra Unit 1 Timur', 'PONPES-U1', [['C', 'L', [['C-101', 2]]]]],
        ['ASR-U2', 'Asrama Putra Unit 2', 'PONPES-U2', [['D', 'L', [['D-101', 2]]]]],
    ];

    foreach ($setup as [$code, $name, $owner, $buildings]) {
        $this->post(route('dormitory.dormitories.store'), ['organization_id' => CoreFixtures::node($this->tenant, $owner)->id, 'code' => $code, 'name' => $name])->assertSessionHasNoErrors();
        $dorm = Dormitory::query()->where('code', $code)->sole();

        foreach ($buildings as [$buildingCode, $gender, $rooms]) {
            $this->post(route('dormitory.buildings.store', ['dormitory' => $dorm->id]), ['code' => $buildingCode, 'name' => "Gedung {$buildingCode}", 'gender' => $gender])->assertSessionHasNoErrors();
            $buildingId = (string) DB::table('dm_buildings')->where('dormitory_id', $dorm->id)->where('code', $buildingCode)->value('id');

            foreach ($rooms as [$roomCode, $beds]) {
                $this->post(route('dormitory.rooms.store', ['building' => $buildingId]), ['code' => $roomCode, 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI'])->assertSessionHasNoErrors();
                $room = Room::query()->where('code', $roomCode)->sole();
                $this->post(route('dormitory.resources.store', ['room' => $room->id]), ['kind' => 'bed', 'prefix' => 'B', 'start' => 1, 'count' => $beds])->assertSessionHasNoErrors();
                $this->room[$roomCode] = $room->id;
            }
        }
    }

    foreach (['Ahmad Fauzi', 'Eko Prasetyo', 'Budi Santoso'] as $name) {
        $this->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => CoreFixtures::node($this->tenant, 'U1-MA')->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();
    }

    $student = fn (string $name): string => (string) DB::table('ac_students as s')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->value('s.membership_id');
    $this->ahmad = $student('Ahmad Fauzi');
    $this->eko = $student('Eko Prasetyo');
    $this->budi = $student('Budi Santoso');

    foreach ([$this->ahmad, $this->eko] as $membershipId) {
        $this->post(route('dormitory.placements.store', ['room' => $this->room['A-101']]), ['membership_id' => $membershipId, 'category' => 'PENGHUNI', 'mode' => 'langsung', 'date' => '2026-08-01'])
            ->assertSessionHasNoErrors();
    }

    $this->ahmadPlacement = dmmActive($this->ahmad)->id;
    $this->ekoPlacement = dmmActive($this->eko)->id;

    $this->pengurus1 = dmmUser($this, 'PONPES-U1', DormitoryAccess::ROLE_PENGURUS_ASRAMA);
    $this->pengurus2 = dmmUser($this, 'PONPES-U2', DormitoryAccess::ROLE_PENGURUS_ASRAMA);
    $this->kepala1 = dmmUser($this, 'PONPES-U1', DormitoryAccess::ROLE_KEPALA_ASRAMA);
    $this->kepala1Membership = (string) Membership::query()->where('person_id', $this->kepala1->person_id)->value('id');
});

function dmmUser(mixed $test, string $nodeCode, string $roleKey): User
{
    $user = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($user, $test->tenant), CoreFixtures::node($test->tenant, $nodeCode)), $roleKey);

    return $user;
}

/** Ganti pengguna (session dikosongkan; akun `guru` memilih lembaga kerja MA Unit 1). */
function dmmAs(mixed $test, User $user): mixed
{
    $test->flushSession();
    $test->actingAs($user);

    if ($user->username === 'guru') {
        DevAccounts::chooseWorkspace($test, $user, 'U1-MA');
    }

    return $test;
}

function dmmActive(string $membershipId): Placement
{
    return Placement::query()->where('membership_id', $membershipId)->where('status', 'AKTIF')->sole();
}

function dmmRequest(mixed $test, string $placementId, string $toRoomId, string $reason = 'Ingin sekamar dengan kelasnya'): mixed
{
    return $test->post(route('dormitory.mutations.store', ['placement' => $placementId]), ['to_room_id' => $toRoomId, 'reason' => $reason]);
}

function dmmRoomCode(Placement $placement): string
{
    return (string) DB::table('dm_rooms')->where('id', $placement->room_id)->value('code');
}

test('ajukan → setujui: dilaksanakan atomik, riwayat bertambah, kartu Beranda; pengaju tidak bisa menyetujui sendiri', function () {
    dmmRequest(dmmAs($this, $this->pengurus1), $this->ahmadPlacement, $this->room['A-102'])->assertRedirect()->assertSessionHasNoErrors();
    $mutation = Mutation::query()->sole();
    expect($mutation->status->value)->toBe('DIAJUKAN')->and($mutation->to_room_id)->toBe($this->room['A-102'])
        ->and(json_decode((string) DB::table('audit_logs')->where('action', DormitoryAuditAction::MUTATION_REQUESTED)->value('changes'), true))
        ->toEqual(['santri' => 'Ahmad Fauzi', 'dari' => 'A-101', 'ke' => 'A-102', 'jenis' => 'BIASA']);

    // Tanda di halaman kamar; satu pengajuan terbuka per santri.
    $this->get(route('dormitory.rooms.show', ['room' => $this->room['A-101']]))
        ->assertInertia(fn (Assert $page) => $page->where('openMutations.'.$this->ahmadPlacement.'.to_room', 'A-102')->where('canRequestMutation', true)->where('canEmergencyMutation', true));
    dmmRequest($this, $this->ahmadPlacement, $this->room['C-101'])
        ->assertSessionHasErrors(['form' => 'Santri ini sudah punya pengajuan mutasi yang menunggu persetujuan. Tarik dulu pengajuan itu.']);

    // Kepala Asrama: kartu Beranda & tab Menunggu.
    dmmAs($this, $this->kepala1)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards) => collect($cards)->firstWhere('key', 'dormitory.mutations_pending')['value'] === 1));
    $this->get(route('dormitory.mutations.index'))
        ->assertInertia(fn (Assert $page) => $page->component('dormitory/mutations')->where('mutations.total', 1)
            ->where('mutations.data.0.can_approve', true)->where('mutations.data.0.can_cancel', false)
            ->where('mutations.data.0.resources.basis', 'BED')->has('mutations.data.0.resources.beds', 1));

    $this->post(route('dormitory.mutations.approve', ['mutation' => $mutation->id]), [])->assertRedirect()->assertSessionHasNoErrors();

    $mutation->refresh();
    $old = Placement::query()->findOrFail($this->ahmadPlacement);
    $new = dmmActive($this->ahmad);
    expect($mutation->status->value)->toBe('DILAKSANAKAN')
        ->and($mutation->executed_on?->toDateString())->toBe('2026-11-15')
        ->and($mutation->new_placement_id)->toBe($new->id)
        ->and($mutation->decided_by)->toBe($this->kepala1Membership)
        ->and($old->status->value)->toBe('SELESAI')->and($old->end_reason?->value)->toBe('MUTASI')->and($old->check_out_on?->toDateString())->toBe('2026-11-15')
        ->and(dmmRoomCode($new))->toBe('A-102')->and($new->bed_id)->not->toBeNull()
        ->and(DB::table('audit_logs')->where('action', DormitoryAuditAction::MUTATION_APPROVED)->exists())->toBeTrue();

    dmmAs($this, $this->admin)->get(route('dormitory.residents.person', ['membership' => $this->ahmad]))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('rows.1.end_reason_label', 'Pindah kamar (mutasi)'));

    // Pengaju tidak bisa menyetujui pengajuannya sendiri (Admin Yayasan punya semua izin).
    dmmRequest($this, $this->ekoPlacement, $this->room['C-101'])->assertSessionHasNoErrors();
    $own = Mutation::query()->where('status', 'DIAJUKAN')->sole();
    $this->post(route('dormitory.mutations.approve', ['mutation' => $own->id]))
        ->assertSessionHasErrors(['form' => 'Pengajuan Anda sendiri tidak bisa Anda setujui. Minta penyetuju lain.']);
    $this->get(route('dormitory.mutations.index'))->assertInertia(fn (Assert $page) => $page->where('mutations.data.0.can_approve', false)->where('mutations.data.0.can_cancel', true));
});

test('tujuan diperiksa saat diajukan; tujuan penuh saat disetujui → tidak ada yang berubah', function () {
    dmmAs($this, $this->admin);
    dmmRequest($this, $this->ahmadPlacement, $this->room['A-101'])->assertSessionHasErrors(['to_room_id' => 'Kamar tujuan sama dengan kamar sekarang.']);
    dmmRequest($this, $this->ahmadPlacement, $this->room['P-101'])->assertSessionHasErrors(['to_room_id' => 'Kamar P-101 untuk putri, sedangkan Ahmad Fauzi putra.']);
    dmmRequest($this, $this->ahmadPlacement, $this->room['D-101'])->assertSessionHasErrors(['to_room_id' => 'Ahmad Fauzi tidak punya pendaftaran aktif di lembaga milik Unit 2.']);
    dmmRequest($this, $this->ahmadPlacement, $this->room['A-102'], '')->assertSessionHasErrors(['reason' => 'Tuliskan alasan mutasi.']);
    dmmRequest($this, $this->ahmadPlacement, $this->room['A-102'])->assertSessionHasNoErrors();

    // Sebelum diputuskan, bed terakhir A-102 diisi Budi.
    $this->post(route('dormitory.placements.store', ['room' => $this->room['A-102']]), ['membership_id' => $this->budi, 'category' => 'PENGHUNI', 'mode' => 'langsung', 'date' => '2026-11-15'])
        ->assertSessionHasNoErrors();
    $mutation = Mutation::query()->sole();
    dmmAs($this, $this->kepala1)->post(route('dormitory.mutations.approve', ['mutation' => $mutation->id]))
        ->assertSessionHasErrors(['form' => 'Kamar A-102 penuh untuk santri (1 dari 1).']);

    expect($mutation->refresh()->status->value)->toBe('DIAJUKAN')
        ->and(dmmActive($this->ahmad)->id)->toBe($this->ahmadPlacement)
        ->and(Placement::query()->where('membership_id', $this->ahmad)->count())->toBe(1);

    // Pilihan tujuan: kamar penuh tetap tampil dengan alasannya; kamar putri & Unit 2 tidak muncul.
    $targets = collect(dmmAs($this, $this->admin)->getJson(route('dormitory.mutations.targets', ['placement' => $this->ahmadPlacement]))->assertOk()->json('targets'))->keyBy('code');
    expect($targets->keys()->all())->toBe(['A-102', 'C-101'])
        ->and($targets['A-102']['blocked'])->toBe('Penuh')
        ->and($targets['C-101']['free'])->toBe(2)->and($targets['C-101']['dormitory'])->toBe('Asrama Putra Unit 1 Timur');
});

test('tolak wajib alasan; tarik hanya pengaju; pengajuan gugur saat check-out', function () {
    dmmRequest(dmmAs($this, $this->pengurus1), $this->ahmadPlacement, $this->room['A-102'])->assertSessionHasNoErrors();
    $mutation = Mutation::query()->sole();

    dmmAs($this, $this->kepala1);
    $this->post(route('dormitory.mutations.cancel', ['mutation' => $mutation->id]))->assertNotFound();
    $this->post(route('dormitory.mutations.reject', ['mutation' => $mutation->id]), ['reason' => ''])->assertSessionHasErrors(['reason' => 'Tuliskan alasan penolakan.']);
    $this->post(route('dormitory.mutations.reject', ['mutation' => $mutation->id]), ['reason' => 'Kamar tujuan untuk kelas 12'])->assertSessionHasNoErrors();
    expect($mutation->refresh()->status->value)->toBe('DITOLAK')->and($mutation->decision_note)->toBe('Kamar tujuan untuk kelas 12');
    $this->post(route('dormitory.mutations.approve', ['mutation' => $mutation->id]))->assertSessionHasErrors(['form' => 'Pengajuan ini sudah ditolak.']);

    dmmRequest(dmmAs($this, $this->pengurus1), $this->ahmadPlacement, $this->room['A-102'])->assertSessionHasNoErrors();
    $second = Mutation::query()->where('status', 'DIAJUKAN')->sole();
    $this->post(route('dormitory.mutations.cancel', ['mutation' => $second->id]))->assertSessionHasNoErrors();
    expect($second->refresh()->status->value)->toBe('DIBATALKAN')->and($second->decision_note)->toBe('Ditarik pengaju.');

    dmmRequest($this, $this->ahmadPlacement, $this->room['A-102'])->assertSessionHasNoErrors();
    $third = Mutation::query()->where('status', 'DIAJUKAN')->sole();
    dmmAs($this, $this->admin)->post(route('dormitory.placements.check-out', ['placement' => $this->ahmadPlacement]), ['date' => '2026-11-15', 'end_reason' => 'KELUAR_ASRAMA'])
        ->assertSessionHasNoErrors();
    expect($third->refresh()->status->value)->toBe('DIBATALKAN')->and($third->decision_note)->toBe('Gugur otomatis: santri check-out.');
});

test('darurat: kategori & alasan wajib, langsung lintas asrama, menggugurkan pengajuan, ditinjau orang lain', function () {
    dmmRequest(dmmAs($this, $this->admin), $this->ahmadPlacement, $this->room['A-102'])->assertSessionHasNoErrors();
    $open = Mutation::query()->sole();

    dmmAs($this, $this->pengurus1);
    $emergency = fn (array $data) => $this->post(route('dormitory.mutations.emergency', ['placement' => $this->ahmadPlacement]), array_merge([
        'to_room_id' => $this->room['C-101'], 'emergency_category' => 'KONFLIK', 'reason' => 'Perkelahian dengan teman sekamar',
    ], $data));

    $emergency(['emergency_category' => ''])->assertSessionHasErrors(['emergency_category' => 'Pilih kategori darurat.']);
    $emergency(['reason' => 'Berkelahi'])->assertSessionHasErrors(['reason' => 'Alasan minimal 10 karakter.']);
    $emergency(['to_room_id' => $this->room['D-101']])->assertForbidden();
    $emergency([])->assertRedirect()->assertSessionHasNoErrors();

    $mutation = Mutation::query()->where('type', 'DARURAT')->sole();
    expect($mutation->status->value)->toBe('DILAKSANAKAN')->and($mutation->emergency_category?->value)->toBe('KONFLIK')
        ->and(dmmRoomCode(dmmActive($this->ahmad)))->toBe('C-101')
        ->and($open->refresh()->status->value)->toBe('DIBATALKAN')
        ->and($open->decision_note)->toBe('Gugur otomatis: santri sudah dimutasi lewat jalur darurat.')
        ->and(json_decode((string) DB::table('audit_logs')->where('action', DormitoryAuditAction::MUTATION_EMERGENCY)->value('changes'), true))
        ->toEqual(['santri' => 'Ahmad Fauzi', 'dari' => 'A-101', 'ke' => 'C-101', 'jenis' => 'DARURAT', 'kategori' => 'KONFLIK']);

    // Eko pindah darurat ke A-102 (1 bed) — kamar itu kini penuh.
    $this->post(route('dormitory.mutations.emergency', ['placement' => $this->ekoPlacement]), ['to_room_id' => $this->room['A-102'], 'emergency_category' => 'KESEHATAN', 'reason' => 'Butuh kamar dekat UKS'])->assertSessionHasNoErrors();

    dmmAs($this, $this->kepala1)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards) => collect($cards)->firstWhere('key', 'dormitory.emergency_unreviewed')['value'] === 2));
    $this->get(route('dormitory.mutations.index', ['tab' => 'darurat']))
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'darurat')->where('mutations.total', 2)->where('mutations.data.0.can_review', true)->where('mutations.data.0.recent', true));
    $this->post(route('dormitory.mutations.review', ['mutation' => $mutation->id]), ['note' => 'Sudah dikonfirmasi ke wali santri'])->assertSessionHasNoErrors();
    $this->post(route('dormitory.mutations.review', ['mutation' => $mutation->id]))->assertSessionHasErrors(['form' => 'Mutasi darurat ini sudah ditinjau.']);
    expect($mutation->refresh()->reviewed_by)->toBe($this->kepala1Membership);

    // Darurat bukan jalan pintas kapasitas; pelaku tidak meninjau sendiri (Admin Yayasan punya izin setujui & darurat).
    dmmAs($this, $this->admin);
    $this->post(route('dormitory.placements.store', ['room' => $this->room['A-101']]), ['membership_id' => $this->budi, 'category' => 'PENGHUNI', 'mode' => 'langsung', 'date' => '2026-11-15'])->assertSessionHasNoErrors();
    $budiEmergency = fn (string $room) => $this->post(route('dormitory.mutations.emergency', ['placement' => dmmActive($this->budi)->id]), ['to_room_id' => $this->room[$room], 'emergency_category' => 'LAINNYA', 'reason' => 'Renovasi kamar mendadak']);
    $budiEmergency('A-102')->assertSessionHasErrors(['form' => 'Kamar A-102 penuh untuk santri (1 dari 1).']);
    $budiEmergency('C-101')->assertSessionHasNoErrors();
    $own = Mutation::query()->where('type', 'DARURAT')->where('requested_by', '<>', $mutation->requested_by)->sole();
    $this->post(route('dormitory.mutations.review', ['mutation' => $own->id]))->assertSessionHasErrors(['form' => 'Mutasi darurat yang Anda lakukan sendiri harus ditinjau orang lain.']);
});

test('wali kamar mengajukan dari Kamar saya: hanya santri kamarnya, tujuan pemilik sama; tidak bisa menyetujui', function () {
    $guru = DevAccounts::user('guru');
    $guruMembership = (string) Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $guru->person_id)->value('id');
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id, membershipId: $guruMembership, name: 'Ustadz Guru', gender: Gender::Male, birthPlace: null, birthDate: null, phone: null,
        address: null, employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: CoreFixtures::node($this->tenant, 'U1-MA')->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));
    DB::table('persons')->where('id', $guru->person_id)->update(['gender' => 'L']);
    $this->post(route('dormitory.supervisors.store', ['room' => $this->room['A-101']]), ['membership_id' => $guruMembership, 'date' => '2026-11-15'])->assertSessionHasNoErrors();
    $this->post(route('dormitory.placements.store', ['room' => $this->room['A-102']]), ['membership_id' => $this->budi, 'category' => 'PENGHUNI', 'mode' => 'langsung', 'date' => '2026-11-15'])->assertSessionHasNoErrors();

    dmmAs($this, $guru);
    $targets = collect($this->getJson(route('dormitory.mutations.targets', ['placement' => $this->ahmadPlacement]))->assertOk()->json('targets'))->pluck('code')->all();
    expect($targets)->toBe(['A-102', 'C-101']);
    dmmRequest($this, $this->ahmadPlacement, $this->room['C-101'])->assertRedirect()->assertSessionHasNoErrors();
    dmmRequest($this, dmmActive($this->budi)->id, $this->room['C-101'])->assertNotFound();
    $this->post(route('dormitory.mutations.emergency', ['placement' => $this->ekoPlacement]), ['to_room_id' => $this->room['C-101'], 'emergency_category' => 'KONFLIK', 'reason' => 'Perkelahian di kamar'])->assertForbidden();

    $mutation = Mutation::query()->sole();
    expect($mutation->requested_by)->toBe($guruMembership);
    $this->get(route('dormitory.my-rooms.index'))->assertInertia(fn (Assert $page) => $page->where('rooms.0.openMutations.'.$this->ahmadPlacement.'.to_room', 'C-101'));
    $this->post(route('dormitory.mutations.approve', ['mutation' => $mutation->id]))->assertNotFound();
    $this->get(route('dormitory.mutations.index'))->assertForbidden();
    $this->post(route('dormitory.mutations.cancel', ['mutation' => $mutation->id]))->assertSessionHasNoErrors();
});

test('hak akses asal & tujuan: unit lain 404, tanpa izin 403, daftar sesuai cakupan', function () {
    dmmRequest(dmmAs($this, $this->pengurus2), $this->ahmadPlacement, $this->room['A-102'])->assertNotFound();
    $this->get(route('dormitory.mutations.index'))->assertInertia(fn (Assert $page) => $page->where('mutations.total', 0));

    dmmRequest(dmmAs($this, $this->kepala1), $this->ahmadPlacement, $this->room['A-102'])->assertForbidden();
    $this->getJson(route('dormitory.mutations.targets', ['placement' => $this->ahmadPlacement, 'jenis' => 'darurat']))->assertForbidden();

    dmmRequest(dmmAs($this, $this->pengurus1), $this->ahmadPlacement, $this->room['D-101'])->assertForbidden();
    dmmRequest($this, $this->ahmadPlacement, $this->room['A-102'])->assertSessionHasNoErrors();
    $mutation = Mutation::query()->sole();
    $this->post(route('dormitory.mutations.approve', ['mutation' => $mutation->id]))->assertForbidden();

    dmmAs($this, $this->pengurus2)->post(route('dormitory.mutations.reject', ['mutation' => $mutation->id]), ['reason' => 'x'])->assertNotFound();
    dmmAs($this, $this->kepala1)->get(route('dormitory.mutations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('mutations.total', 1)->where('counts.pending', 1));
});

test('database: satu pengajuan terbuka per santri, tujuan ≠ asal, darurat wajib kategori & dilaksanakan', function () {
    dmmRequest(dmmAs($this, $this->pengurus1), $this->ahmadPlacement, $this->room['A-102'])->assertSessionHasNoErrors();
    $unit1 = CoreFixtures::node($this->tenant, 'PONPES-U1')->id;
    $dorm = (string) DB::table('dm_rooms')->where('id', $this->room['A-101'])->value('dormitory_id');

    $insert = fn (array $overrides) => DB::transaction(fn () => DB::table('dm_mutations')->insert(array_merge([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id,
        'organization_id' => $unit1, 'dormitory_id' => $dorm, 'from_room_id' => $this->room['A-101'], 'placement_id' => $this->ekoPlacement,
        'to_organization_id' => $unit1, 'to_dormitory_id' => $dorm, 'to_room_id' => $this->room['A-102'],
        'membership_id' => $this->eko, 'type' => 'BIASA', 'status' => 'DIAJUKAN', 'reason' => 'Uji', 'created_at' => now(), 'updated_at' => now(),
    ], $overrides)));

    expect(fn () => $insert(['membership_id' => $this->ahmad, 'placement_id' => $this->ahmadPlacement]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['to_room_id' => $this->room['A-101']]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['type' => 'DARURAT']))->toThrow(QueryException::class)
        ->and(fn () => $insert(['status' => 'DILAKSANAKAN']))->toThrow(QueryException::class)
        ->and(fn () => $insert(['status' => 'DITOLAK']))->toThrow(QueryException::class)
        ->and(fn () => $insert(['to_organization_id' => CoreFixtures::node($this->tenant, 'PONPES-U2')->id]))->toThrow(QueryException::class);

    $insert([]);
    expect(Mutation::query()->count())->toBe(2);
});
