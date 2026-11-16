<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Import\PendingPlacementImport;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Placement\Placement;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Impor penempatan DM-5b (PRD-008 §20).
 *
 * Data uji (hari ini 16 Nov 2026): "Asrama Putra Unit 1" → Gedung A (putra): A-101 (2 bed), A-102 (1 bed);
 * Gedung P (putri): P-101 (2 bed). Santri Unit 1: Ahmad (NISN 0012345671), Budi (NISN 0012345672), Eko (NIS S-001,
 * MA), Fajar (NIS S-001, MTs → NIS ganda), Gilang (NIS G-07), Citra (putri, NISN 0012345679). Dodi (Unit 2, NISN 0099999991).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00', BusinessClock::timezone()));
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->actingAs($this->admin);

    $this->post(route('dormitory.dormitories.store'), ['organization_id' => CoreFixtures::node($this->tenant, 'PONPES-U1')->id, 'code' => 'ASR-U1', 'name' => 'Asrama Putra Unit 1'])->assertSessionHasNoErrors();
    $this->dorm = Dormitory::query()->sole();

    foreach ([['A', 'L', [['A-101', 2], ['A-102', 1]]], ['P', 'P', [['P-101', 2]]]] as [$code, $gender, $rooms]) {
        $this->post(route('dormitory.buildings.store', ['dormitory' => $this->dorm->id]), ['code' => $code, 'name' => "Gedung {$code}", 'gender' => $gender])->assertSessionHasNoErrors();
        $buildingId = (string) DB::table('dm_buildings')->where('code', $code)->value('id');

        foreach ($rooms as [$roomCode, $beds]) {
            $this->post(route('dormitory.rooms.store', ['building' => $buildingId]), ['code' => $roomCode, 'capacity_basis' => 'BED', 'composition' => 'KHUSUS_SANTRI'])->assertSessionHasNoErrors();
            $room = Room::query()->where('code', $roomCode)->sole();
            $this->post(route('dormitory.resources.store', ['room' => $room->id]), ['kind' => 'bed', 'prefix' => 'B', 'start' => 1, 'count' => $beds])->assertSessionHasNoErrors();
        }
    }

    $this->student = [];

    foreach ([
        ['Ahmad', 'L', 'U1-MA', '0012345671', null],
        ['Budi', 'L', 'U1-MA', '0012345672', null],
        ['Eko', 'L', 'U1-MA', null, 'S-001'],
        ['Fajar', 'L', 'U1-MTS', null, 'S-001'],
        ['Gilang', 'L', 'U1-MA', null, 'G-07'],
        ['Citra', 'P', 'U1-MA', '0012345679', null],
        ['Dodi', 'L', 'U2-MA', '0099999991', null],
    ] as [$name, $gender, $org, $nisn, $nis]) {
        $this->post(route('academic.students.store'), array_filter([
            'name' => $name, 'gender' => $gender, 'organization_id' => CoreFixtures::node($this->tenant, $org)->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
            'nisn' => $nisn, 'student_number' => $nis,
        ]))->assertSessionHasNoErrors();
        $this->student[$name] = (string) DB::table('ac_students as s')->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->value('s.membership_id');
    }
});

/**
 * @param  list<array<string, string>>  $rows
 */
function dmiUpload(mixed $test, array $rows, ?string $dormitoryId = null): mixed
{
    $columns = ['nisn', 'nis', 'kode_gedung', 'kode_kamar', 'kode_bed', 'kode_loker', 'jenis', 'tanggal', 'catatan'];
    $lines = [implode(';', $columns)];

    foreach ($rows as $row) {
        $lines[] = implode(';', array_map(fn (string $column): string => $row[$column] ?? '', $columns));
    }

    return $test->post(route('dormitory.import.upload', ['dormitory' => $dormitoryId ?? $test->dorm->id]), [
        'file' => UploadedFile::fake()->createWithContent('penempatan.csv', implode("\r\n", $lines)."\r\n"),
    ]);
}

/**
 * @return array<string, string>
 */
function dmiRow(array $values): array
{
    return array_merge(['kode_gedung' => 'A', 'kode_kamar' => 'A-101', 'jenis' => 'LANGSUNG', 'tanggal' => '2026-11-16'], $values);
}

function dmiUser(mixed $test, string $nodeCode, string $roleKey): User
{
    $user = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($user, $test->tenant), CoreFixtures::node($test->tenant, $nodeCode)), $roleKey);
    $test->flushSession();

    return $user;
}

test('pratinjau: aturan penempatan sama, berurutan dalam file; NISN/NIS; tidak ada yang tersimpan', function () {
    dmiUpload($this, [
        dmiRow(['nisn' => '0012345671', 'kode_bed' => 'B02']),                          // 2: Ahmad → A-101 B02
        dmiRow(['nisn' => '0012345672', 'kode_bed' => 'b02']),                          // 3: bed sama dengan baris 2
        dmiRow(['nis' => 'S-001']),                                                    // 4: NIS ganda (MA & MTs)
        dmiRow(['nis' => 'G-07', 'kode_kamar' => 'A-102', 'jenis' => 'rencana', 'tanggal' => '']), // 5: Gilang rencana A-102
        dmiRow(['nisn' => '0012345672', 'kode_kamar' => 'A-102']),                      // 6: A-102 penuh karena baris 5
        dmiRow(['nisn' => '0012345679']),                                              // 7: Citra putri di gedung putra
        dmiRow(['nisn' => '0099999991']),                                              // 8: Dodi Unit 2
        dmiRow(['nisn' => '0012345671', 'kode_kamar' => 'A-102']),                      // 9: Ahmad dua kali
        dmiRow(['nisn' => '0012345672', 'kode_kamar' => 'X-9']),                        // 10: kamar tidak ada
        dmiRow(['nisn' => '0012345672', 'jenis' => 'PINDAH']),                          // 11: jenis salah
        dmiRow(['nisn' => '0012345672', 'tanggal' => '']),                              // 12: tanggal wajib
        dmiRow(['nisn' => '0012345672', 'tanggal' => '2026-11-17']),                    // 13: masa depan
        dmiRow(['kode_bed' => 'B01']),                                                 // 14: tanpa identitas
        dmiRow(['nisn' => '0012345672', 'kode_bed' => 'B09']),                          // 15: bed tidak ada
        dmiRow(['nisn' => '0012345672']),                                              // 16: Budi → bed otomatis B01
    ])->assertRedirect(route('dormitory.import.index', ['dormitory' => $this->dorm->id]))->assertSessionHasNoErrors();

    $this->get(route('dormitory.import.index', ['dormitory' => $this->dorm->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dormitory/import')
            ->where('pending.name', 'penempatan.csv')
            ->where('preview.ready', 3)->where('preview.errors', 12)
            ->where('preview.rooms', ['A / A-101' => 2, 'A / A-102' => 1])
            ->where('preview.rows.0.name', 'Ahmad')->where('preview.rows.0.bed', 'B02')->where('preview.rows.0.error', null)
            ->where('preview.rows.1.error', 'Bed B02 sudah dipakai atau dipesan penghuni lain.')
            ->where('preview.rows.2.error', 'NIS S-001 cocok dengan lebih dari satu santri. Isi NISN-nya.')
            ->where('preview.rows.3.name', 'Gilang')->where('preview.rows.3.mode', 'Rencana')->where('preview.rows.3.error', null)
            ->where('preview.rows.4.error', 'Kamar A-102 penuh untuk santri (1 dari 1).')
            ->where('preview.rows.5.error', 'Kamar A-101 untuk putra, sedangkan Citra putri.')
            ->where('preview.rows.6.error', 'Santri dengan NISN 0099999991 tidak ditemukan di lembaga milik asrama ini (atau pendaftarannya sudah ditutup).')
            ->where('preview.rows.7.error', 'Ahmad sudah tinggal di kamar A-101 (Asrama Putra Unit 1).')
            ->where('preview.rows.8.error', 'Kamar X-9 di gedung A tidak ada di asrama ini.')
            ->where('preview.rows.9.error', 'Jenis harus LANGSUNG atau RENCANA.')
            ->where('preview.rows.10.error', 'Isi tanggal masuk untuk jenis LANGSUNG.')
            ->where('preview.rows.11.error', 'Tanggal masuk tidak boleh di masa depan.')
            ->where('preview.rows.12.error', 'Isi NISN atau NIS santri.')
            ->where('preview.rows.13.error', 'Bed B09 tidak ada di kamar A-101.')
            ->where('preview.rows.14.name', 'Budi')->where('preview.rows.14.bed', 'B01')->where('preview.rows.14.line', 16));

    expect(Placement::query()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'like', 'dormitory.placement%')->count())->toBe(0);

    // Ada baris salah → tidak ada yang disimpan; file tetap ada.
    $this->post(route('dormitory.import.save', ['dormitory' => $this->dorm->id]))
        ->assertSessionHasErrors(['save' => 'Tidak ada yang disimpan: 12 baris salah. Perbaiki file lalu unggah ulang.']);
    expect(Placement::query()->count())->toBe(0)
        ->and(app(PendingPlacementImport::class)->current(session()->driver(), $this->tenant->id))->not->toBeNull();
});

test('simpan semua: tersimpan sekaligus, tercatat di audit, file dihapus; batal menghapus file', function () {
    dmiUpload($this, [
        dmiRow(['nisn' => '0012345671']),
        dmiRow(['nis' => 'g-07'.'', 'kode_bed' => 'B02', 'catatan' => 'Pindahan']),
        dmiRow(['nisn' => '0012345672', 'kode_kamar' => 'A-102', 'jenis' => 'RENCANA', 'tanggal' => '2027-07-13']),
    ])->assertSessionHasNoErrors();

    $this->get(route('dormitory.import.index', ['dormitory' => $this->dorm->id]))
        ->assertInertia(fn (Assert $page) => $page->where('preview.ready', 2)->where('preview.errors', 1)
            ->where('preview.rows.1.error', 'Santri dengan NIS g-07 tidak ditemukan di lembaga milik asrama ini (atau pendaftarannya sudah ditutup).'));

    dmiUpload($this, [
        dmiRow(['nisn' => '0012345671']),
        dmiRow(['nis' => 'G-07', 'kode_bed' => 'B02', 'catatan' => 'Pindahan']),
        dmiRow(['nisn' => '0012345672', 'kode_kamar' => 'A-102', 'jenis' => 'RENCANA', 'tanggal' => '2027-07-13']),
    ])->assertSessionHasNoErrors();

    $this->post(route('dormitory.import.save', ['dormitory' => $this->dorm->id]))
        ->assertRedirect('/asrama/'.$this->dorm->id.'?tab=penghuni')->assertSessionHasNoErrors();

    $placement = fn (string $name): Placement => Placement::query()->where('membership_id', $this->student[$name])->sole();
    expect($placement('Ahmad')->status->value)->toBe('AKTIF')
        ->and(DB::table('dm_beds')->where('id', $placement('Ahmad')->bed_id)->value('code'))->toBe('B01')
        ->and(DB::table('dm_beds')->where('id', $placement('Gilang')->bed_id)->value('code'))->toBe('B02')
        ->and($placement('Gilang')->note)->toBe('Pindahan')
        ->and($placement('Budi')->status->value)->toBe('DIRENCANAKAN')
        ->and($placement('Budi')->planned_on?->toDateString())->toBe('2027-07-13')
        ->and(json_decode((string) DB::table('audit_logs')->where('action', DormitoryAuditAction::PLACEMENTS_IMPORTED)->value('changes'), true))->toEqual(['asrama' => 'ASR-U1', 'baris' => 3])
        ->and(DB::table('audit_logs')->where('action', DormitoryAuditAction::PLACEMENT_CHECKED_IN)->count())->toBe(2)
        ->and(app(PendingPlacementImport::class)->current(session()->driver(), $this->tenant->id))->toBeNull();

    $this->post(route('dormitory.import.save', ['dormitory' => $this->dorm->id]))
        ->assertSessionHasErrors(['save' => 'Belum ada file impor untuk asrama ini. Unggah file terlebih dahulu.']);

    dmiUpload($this, [dmiRow(['nisn' => '0012345679'])])->assertSessionHasNoErrors();
    $this->post(route('dormitory.import.cancel', ['dormitory' => $this->dorm->id]))->assertRedirect();
    expect(app(PendingPlacementImport::class)->current(session()->driver(), $this->tenant->id))->toBeNull();
});

test('file ditolak: format & judul kolom; template berisi kamar & bed kosong', function () {
    $this->post(route('dormitory.import.upload', ['dormitory' => $this->dorm->id]), ['file' => UploadedFile::fake()->createWithContent('a.txt', 'x')])
        ->assertSessionHasErrors(['file' => 'File harus berformat .xlsx atau .csv.']);
    $this->post(route('dormitory.import.upload', ['dormitory' => $this->dorm->id]), ['file' => UploadedFile::fake()->createWithContent('a.csv', "nisn;kode_kamar\r\n1;A\r\n")])
        ->assertSessionHasErrors('file');

    $this->post(route('dormitory.placements.store', ['room' => Room::query()->where('code', 'A-101')->value('id')]), ['membership_id' => $this->student['Ahmad'], 'category' => 'PENGHUNI', 'mode' => 'langsung', 'date' => '2026-11-16'])
        ->assertSessionHasNoErrors();

    $response = $this->get(route('dormitory.import.template', ['dormitory' => $this->dorm->id]))->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();
    $reader = new XlsxReader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();

    expect(array_keys($sheets))->toBe(['Penempatan', 'Petunjuk'])
        ->and($sheets['Penempatan'][0])->toBe(['nisn', 'nis', 'kode_gedung *', 'kode_kamar *', 'kode_bed', 'kode_loker', 'jenis *', 'tanggal', 'catatan'])
        ->and(collect($sheets['Petunjuk'])->first(fn ($row) => ($row[1] ?? null) === 'A-101'))->toBe(['A', 'A-101', 'Putra', 1, 'B02', ''])
        ->and(collect($sheets['Petunjuk'])->first(fn ($row) => ($row[1] ?? null) === 'P-101'))->toBe(['P', 'P-101', 'Putri', 2, 'B01, B02', '']);
});

test('hak akses: kepala asrama 403, pengurus unit lain 404, file asrama lain tidak terbawa', function () {
    $kepala = dmiUser($this, 'PONPES-U1', DormitoryAccess::ROLE_KEPALA_ASRAMA);
    $this->actingAs($kepala)->get(route('dormitory.import.index', ['dormitory' => $this->dorm->id]))->assertForbidden();
    $this->actingAs($kepala)->get(route('dormitory.import.template', ['dormitory' => $this->dorm->id]))->assertForbidden();

    $pengurus2 = dmiUser($this, 'PONPES-U2', DormitoryAccess::ROLE_PENGURUS_ASRAMA);
    dmiUpload($this->actingAs($pengurus2), [dmiRow(['nisn' => '0012345671'])])->assertNotFound();

    $pengurus1 = dmiUser($this, 'PONPES-U1', DormitoryAccess::ROLE_PENGURUS_ASRAMA);
    $this->actingAs($pengurus1);
    $this->post(route('dormitory.dormitories.store'), ['organization_id' => CoreFixtures::node($this->tenant, 'PONPES-U1')->id, 'code' => 'ASR-U1B', 'name' => 'Asrama Kedua'])->assertSessionHasNoErrors();
    $second = Dormitory::query()->where('code', 'ASR-U1B')->sole();

    dmiUpload($this, [dmiRow(['nisn' => '0012345671'])])->assertSessionHasNoErrors();
    $this->get(route('dormitory.import.index', ['dormitory' => $second->id]))->assertInertia(fn (Assert $page) => $page->where('pending', null)->where('preview', null));
    $this->post(route('dormitory.import.save', ['dormitory' => $second->id]))->assertSessionHasErrors('save');
    $this->post(route('dormitory.import.save', ['dormitory' => $this->dorm->id]))->assertSessionHasNoErrors();
    expect(Placement::query()->count())->toBe(1);
});
