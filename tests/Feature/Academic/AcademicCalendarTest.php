<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Calendar\AcademicCalendarResolver;
use Modules\Academic\Application\Calendar\CalendarSetting;
use Modules\Academic\Application\Calendar\ManageCalendars;
use Modules\Academic\Database\Seeders\AcademicCalendarSeeder;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Kalender akademik (AC-1a, PRD-002 §4.4): kalender, tahun ajaran & semester, pemakaian kalender.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicCalendarSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->default = AcademicCalendar::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_default', true)->sole();
});

/**
 * Isian form tahun ajaran Juli–Juni dengan dua semester.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kalTahun(int $first, array $overrides = []): array
{
    $second = $first + 1;

    return array_merge([
        'name' => "{$first}/{$second}",
        'start_date' => "{$first}-07-01",
        'end_date' => "{$second}-06-30",
        'terms' => [
            ['name' => 'Semester Ganjil', 'start_date' => "{$first}-07-01", 'end_date' => "{$first}-12-31"],
            ['name' => 'Semester Genap', 'start_date' => "{$second}-01-01", 'end_date' => "{$second}-06-30"],
        ],
    ], $overrides);
}

function kalYear(AcademicCalendar $calendar, string $name): AcademicYear
{
    return AcademicYear::query()->withoutGlobalScope('tenant')->where('calendar_id', $calendar->id)->where('name', $name)->sole();
}

function kalAudit(string $action): int
{
    return DB::table('audit_logs')->where('action', $action)->count();
}

// ── Isi awal ──────────────────────────────────────────────────────────────────

test('Kalender Yayasan dibuat untuk setiap yayasan dan seeder aman dijalankan berulang', function () {
    expect(AcademicCalendar::query()->withoutGlobalScope('tenant')->count())->toBe(1)
        ->and($this->default->name)->toBe(ManageCalendars::DEFAULT_NAME)
        ->and(kalAudit(AcademicAuditAction::CALENDAR_CREATED))->toBe(1);

    // Owner sudah mengganti namanya; seeder tidak membuat kalender default kedua.
    app(ManageCalendars::class)->rename($this->tenant->id, $this->default->id, 'Kalender Pendidikan');
    $this->seed(AcademicCalendarSeeder::class);

    CoreFixtures::tenant('YYS-BARU');
    $this->seed(AcademicCalendarSeeder::class);

    expect(AcademicCalendar::query()->withoutGlobalScope('tenant')->count())->toBe(2)
        ->and($this->default->refresh()->name)->toBe('Kalender Pendidikan')
        ->and(kalAudit(AcademicAuditAction::CALENDAR_CREATED))->toBe(2);
});

test('seeder data uji membuat operator.smp dan satu tahun ajaran berjalan, aman diulang', function () {
    $this->seed(AcademicDevSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $years = AcademicYear::query()->withoutGlobalScope('tenant')->where('calendar_id', $this->default->id)->get();

    expect($years)->toHaveCount(1)
        ->and($years[0]->is_current)->toBeTrue()
        ->and(Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $years[0]->id)->count())->toBe(2);

    // Operator Akademik di SMP Unit 3: satu penugasan → langsung masuk lembaga kerjanya.
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => collect($permissions)->contains(AcademicAccess::STUDENTS_MANAGE)
            && ! collect($permissions)->contains(AcademicAccess::CALENDAR_MANAGE)));
});

// ── Halaman & hak akses ───────────────────────────────────────────────────────

test('Admin Yayasan membuka Kalender akademik; semua lembaga aktif memakai kalender bawaan', function () {
    $this->actingAs($this->admin)
        ->get(route('academic.calendar.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/calendar')
            ->has('calendars', 1)
            ->where('selected.id', $this->default->id)
            ->where('selected.is_default', true)
            ->has('selected.years', 0)
            ->has('rules', 0)
            // 16 lembaga di pohon tenant pertama (PRD-000 §4.3): Ponpes + 5 per unit × 3 unit.
            ->has('usage', 16)
            ->where('usage.0.calendar', ManageCalendars::DEFAULT_NAME)
            ->where('usage.0.source', 'Bawaan')
            ->has('options.jenjang', 8)
            ->where('permissions', fn ($permissions) => collect($permissions)->contains(AcademicAccess::CALENDAR_MANAGE)),
        );
});

test('tamu diarahkan ke login; selain Admin Yayasan di tingkat Yayasan: 403', function () {
    $this->get(route('academic.calendar.index'))->assertRedirect(route('login'));

    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('academic.calendar.index'))->assertForbidden();
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('academic.calendar.store'), ['name' => 'Kalender Unit 1'])
        ->assertForbidden();
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('academic.years.store', ['calendar' => $this->default->id]), kalTahun(2026))
        ->assertForbidden();

    $this->seed(AcademicDevSeeder::class);
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))
        ->get(route('academic.calendar.index'))
        ->assertForbidden();

    expect(AcademicCalendar::query()->withoutGlobalScope('tenant')->count())->toBe(1);
});

test('kalender atau tahun ajaran milik yayasan lain, atau ID asal-asalan: 404', function () {
    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    $kalenderLain = app(ManageCalendars::class)->ensureDefault($tenantLain->id);

    $this->actingAs($this->admin)
        ->patch(route('academic.calendar.update', ['calendar' => $kalenderLain->id]), ['name' => 'Diambil'])
        ->assertNotFound();
    $this->actingAs($this->admin)
        ->post(route('academic.years.store', ['calendar' => $kalenderLain->id]), kalTahun(2026))
        ->assertNotFound();

    $this->actingAs($this->admin)->post(route('academic.years.store', ['calendar' => $this->default->id]), kalTahun(2026));
    $tahun = kalYear($this->default, '2026/2027');

    // Halaman dengan ?kalender= milik yayasan lain tetap menampilkan kalender bawaan sendiri.
    $this->actingAs($this->admin)
        ->get(route('academic.calendar.index', ['kalender' => $kalenderLain->id]))
        ->assertInertia(fn (Assert $page) => $page->where('selected.id', $this->default->id));

    $this->actingAs($this->admin)
        ->post(route('academic.years.current', ['year' => '01900000-0000-7000-8000-000000000000']))
        ->assertNotFound();

    expect($kalenderLain->refresh()->name)->toBe(ManageCalendars::DEFAULT_NAME)
        ->and($tahun->is_current)->toBeTrue();
});

// ── Kalender ──────────────────────────────────────────────────────────────────

test('menambah dan mengubah nama kalender; nama dobel ditolak tanpa beda huruf besar/kecil', function () {
    $this->actingAs($this->admin)
        ->post(route('academic.calendar.store'), ['name' => '  Kalender   MDA '])
        ->assertSessionHasNoErrors();

    $mda = AcademicCalendar::query()->withoutGlobalScope('tenant')->where('name', 'Kalender MDA')->sole();

    expect($mda->is_default)->toBeFalse();

    $this->actingAs($this->admin)
        ->post(route('academic.calendar.store'), ['name' => 'kalender mda'])
        ->assertSessionHasErrors(['name' => 'Kalender bernama kalender mda sudah ada.']);
    $this->actingAs($this->admin)
        ->post(route('academic.calendar.store'), ['name' => ''])
        ->assertSessionHasErrors('name');
    $this->actingAs($this->admin)
        ->patch(route('academic.calendar.update', ['calendar' => $mda->id]), ['name' => 'kalender yayasan'])
        ->assertSessionHasErrors('name');

    $this->actingAs($this->admin)
        ->patch(route('academic.calendar.update', ['calendar' => $mda->id]), ['name' => 'Kalender Madrasah Diniyah'])
        ->assertSessionHasNoErrors();

    expect($mda->refresh()->name)->toBe('Kalender Madrasah Diniyah')
        ->and(kalAudit(AcademicAuditAction::CALENDAR_CREATED))->toBe(2)
        ->and(kalAudit(AcademicAuditAction::CALENDAR_RENAMED))->toBe(1)
        ->and(AuditAction::label(AcademicAuditAction::CALENDAR_CREATED))->toBe('Kalender akademik ditambahkan');
});

// ── Tahun ajaran ──────────────────────────────────────────────────────────────

test('tahun ajaran pertama otomatis berjalan; tahun berjalan dipindah dengan tombol', function () {
    $url = route('academic.years.store', ['calendar' => $this->default->id]);

    $this->actingAs($this->admin)->post($url, kalTahun(2026))->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($this->admin)->post($url, kalTahun(2027))->assertSessionHasNoErrors();

    $lama = kalYear($this->default, '2026/2027');
    $baru = kalYear($this->default, '2027/2028');

    expect($lama->is_current)->toBeTrue()
        ->and($baru->is_current)->toBeFalse()
        ->and($lama->terms()->orderBy('sequence')->pluck('name')->all())->toBe(['Semester Ganjil', 'Semester Genap']);

    $this->actingAs($this->admin)
        ->post(route('academic.years.current', ['year' => $baru->id]))
        ->assertSessionHasNoErrors();

    expect($lama->refresh()->is_current)->toBeFalse()
        ->and($baru->refresh()->is_current)->toBeTrue()
        ->and(kalAudit(AcademicAuditAction::YEAR_CREATED))->toBe(2)
        ->and(kalAudit(AcademicAuditAction::YEAR_SET_CURRENT))->toBe(1);

    $changes = json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::YEAR_SET_CURRENT)->value('changes'), true);

    expect($changes['tahun_berjalan'])->toBe(['2026/2027', '2027/2028']);

    // Menekan lagi pada tahun yang sudah berjalan tidak mencatat apa pun.
    $this->actingAs($this->admin)->post(route('academic.years.current', ['year' => $baru->id]))->assertSessionHasNoErrors();

    expect(kalAudit(AcademicAuditAction::YEAR_SET_CURRENT))->toBe(1);
});

test('tahun ajaran Hijriah, caturwulan, dan satu semester diterima', function () {
    $url = route('academic.years.store', ['calendar' => $this->default->id]);

    $this->actingAs($this->admin)->post($url, kalTahun(2026, [
        'terms' => [
            ['name' => 'Caturwulan 1', 'start_date' => '2026-07-01', 'end_date' => '2026-10-31'],
            ['name' => 'Caturwulan 2', 'start_date' => '2026-11-01', 'end_date' => '2027-02-28'],
            ['name' => 'Caturwulan 3', 'start_date' => '2027-03-01', 'end_date' => '2027-06-30'],
        ],
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post($url, [
        'name' => '1449/1450',
        'start_date' => '2027-07-01',
        'end_date' => '2028-06-15',
        'terms' => [['name' => 'Satu tahun penuh', 'start_date' => '2027-07-01', 'end_date' => '2028-06-15']],
    ])->assertSessionHasNoErrors();

    expect(kalYear($this->default, '2026/2027')->terms()->count())->toBe(3)
        ->and(kalYear($this->default, '1449/1450')->terms()->count())->toBe(1);
});

test('isian tahun ajaran yang salah ditolak dengan pesan di isian terkait', function (string $case) {
    $this->actingAs($this->admin)->post(route('academic.years.store', ['calendar' => $this->default->id]), kalTahun(2025));

    [$overrides, $field] = match ($case) {
        'nama bukan TTTT/TTTT' => [['name' => '2026-2027'], 'name'],
        'tahun kedua bukan +1' => [['name' => '2026/2028'], 'name'],
        'nama sudah ada' => [['name' => '2025/2026', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30'], 'name'],
        'selesai sebelum mulai' => [['end_date' => '2026-06-30', 'terms' => [['name' => 'S1', 'start_date' => '2026-07-01', 'end_date' => '2026-07-02']]], 'end_date'],
        'lebih dari 18 bulan' => [['end_date' => '2028-06-30'], 'end_date'],
        'tanggal tidak ada' => [['end_date' => '2027-02-30'], 'end_date'],
        'tumpang tindih tahun lain' => [['start_date' => '2026-06-01', 'terms' => [['name' => 'S1', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30']]], 'start_date'],
        'tanpa semester' => [['terms' => []], 'terms'],
        'lima semester' => [['terms' => array_fill(0, 5, ['name' => 'S', 'start_date' => '2026-07-01', 'end_date' => '2026-07-02'])], 'terms'],
        'nama semester kosong' => [['terms' => [['name' => ' ', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30']]], 'terms.0.name'],
        'semester di luar tahun ajaran' => [['terms' => [['name' => 'S1', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31']]], 'terms.0.start_date'],
        'semester terbalik' => [['terms' => [['name' => 'S1', 'start_date' => '2026-12-31', 'end_date' => '2026-07-01']]], 'terms.0.end_date'],
        'semester tumpang tindih' => [['terms' => [
            ['name' => 'S1', 'start_date' => '2026-07-01', 'end_date' => '2027-01-15'],
            ['name' => 'S2', 'start_date' => '2027-01-10', 'end_date' => '2027-06-30'],
        ]], 'terms.1.start_date'],
    };

    $this->actingAs($this->admin)
        ->post(route('academic.years.store', ['calendar' => $this->default->id]), kalTahun(2026, $overrides))
        ->assertSessionHasErrors($field);

    expect(AcademicYear::query()->withoutGlobalScope('tenant')->count())->toBe(1);
})->with([
    'nama bukan TTTT/TTTT',
    'tahun kedua bukan +1',
    'nama sudah ada',
    'selesai sebelum mulai',
    'lebih dari 18 bulan',
    'tanggal tidak ada',
    'tumpang tindih tahun lain',
    'tanpa semester',
    'lima semester',
    'nama semester kosong',
    'semester di luar tahun ajaran',
    'semester terbalik',
    'semester tumpang tindih',
]);

test('mengubah tahun ajaran: tanggal & jumlah semester berubah, audit mencatat yang berubah saja', function () {
    $this->actingAs($this->admin)->post(route('academic.years.store', ['calendar' => $this->default->id]), kalTahun(2026));
    $tahun = kalYear($this->default, '2026/2027');
    $url = route('academic.years.update', ['year' => $tahun->id]);

    // Dua semester → tiga caturwulan.
    $this->actingAs($this->admin)->patch($url, kalTahun(2026, [
        'start_date' => '2026-07-13',
        'terms' => [
            ['name' => 'Caturwulan 1', 'start_date' => '2026-07-13', 'end_date' => '2026-10-31'],
            ['name' => 'Caturwulan 2', 'start_date' => '2026-11-01', 'end_date' => '2027-02-28'],
            ['name' => 'Caturwulan 3', 'start_date' => '2027-03-01', 'end_date' => '2027-06-30'],
        ],
    ]))->assertSessionHasNoErrors();

    expect($tahun->refresh()->start_date->toDateString())->toBe('2026-07-13')
        ->and($tahun->is_current)->toBeTrue()
        ->and($tahun->terms()->orderBy('sequence')->pluck('name')->all())->toBe(['Caturwulan 1', 'Caturwulan 2', 'Caturwulan 3']);

    // Kembali ke satu semester: kelebihannya dihapus.
    $this->actingAs($this->admin)->patch($url, kalTahun(2026, [
        'start_date' => '2026-07-13',
        'terms' => [['name' => 'Tahunan', 'start_date' => '2026-07-13', 'end_date' => '2027-06-30']],
    ]))->assertSessionHasNoErrors();

    expect($tahun->terms()->count())->toBe(1);

    // Dua perubahan tercatat; cari berdasarkan isi, bukan urutan waktu (presisi created_at hanya detik).
    $logs = DB::table('audit_logs')
        ->where('action', AcademicAuditAction::YEAR_UPDATED)
        ->where('subject_id', $tahun->id)
        ->pluck('changes')
        ->map(fn ($changes): array => json_decode((string) $changes, true));
    $changes = $logs->first(fn (array $log): bool => array_key_exists('start_date', $log));

    expect($logs)->toHaveCount(2)
        ->and($changes)->toHaveKeys(['kalender', 'start_date', 'semester'])
        ->and($changes)->not->toHaveKey('name')
        ->and($changes['start_date'])->toBe(['2026-07-01', '2026-07-13']);

    // Simpan tanpa perubahan tidak mencatat audit baru.
    $before = kalAudit(AcademicAuditAction::YEAR_UPDATED);
    $this->actingAs($this->admin)->patch($url, kalTahun(2026, [
        'start_date' => '2026-07-13',
        'terms' => [['name' => 'Tahunan', 'start_date' => '2026-07-13', 'end_date' => '2027-06-30']],
    ]))->assertSessionHasNoErrors();

    expect(kalAudit(AcademicAuditAction::YEAR_UPDATED))->toBe($before);

    // Mengubah menjadi tumpang tindih dengan tahun lain ditolak; tahun itu sendiri tidak dihitung.
    $this->actingAs($this->admin)->post(route('academic.years.store', ['calendar' => $this->default->id]), kalTahun(2027));
    $this->actingAs($this->admin)
        ->patch($url, kalTahun(2026, ['end_date' => '2027-07-15', 'terms' => [['name' => 'Tahunan', 'start_date' => '2026-07-01', 'end_date' => '2027-07-15']]]))
        ->assertSessionHasErrors(['start_date' => 'Tanggalnya bertumpang tindih dengan tahun ajaran 2027/2028 di kalender ini.']);
});

// ── Pemakaian kalender ────────────────────────────────────────────────────────

test('MDA memakai Kalender MDA lewat aturan berjenjang; SMP tetap memakai Kalender Yayasan', function () {
    $resolver = app(AcademicCalendarResolver::class);

    $this->actingAs($this->admin)->post(route('academic.years.store', ['calendar' => $this->default->id]), kalTahun(2026));
    $this->actingAs($this->admin)->post(route('academic.calendar.store'), ['name' => 'Kalender MDA']);
    $mda = AcademicCalendar::query()->withoutGlobalScope('tenant')->where('name', 'Kalender MDA')->sole();
    $this->actingAs($this->admin)->post(route('academic.years.store', ['calendar' => $mda->id]), [
        'name' => '1447/1448',
        'start_date' => '2025-06-27',
        'end_date' => '2026-06-15',
        'terms' => [['name' => 'Semester 1', 'start_date' => '2025-06-27', 'end_date' => '2026-06-15']],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post(route('academic.calendar.assign'), ['target' => 'jenjang', 'jenjang' => 'MDA', 'calendar_id' => $mda->id])
        ->assertSessionHasNoErrors();

    $u3Mda = CoreFixtures::node($this->tenant, 'U3-MDA')->id;
    $u3Smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;

    expect($resolver->calendarFor($this->tenant->id, $u3Mda)?->id)->toBe($mda->id)
        ->and($resolver->currentYearFor($this->tenant->id, $u3Mda)?->name)->toBe('1447/1448')
        ->and($resolver->calendarFor($this->tenant->id, $u3Smp)?->id)->toBe($this->default->id)
        ->and($resolver->currentYearFor($this->tenant->id, $u3Smp)?->name)->toBe('2026/2027');

    // Pengaturan lembaga menang atas pengaturan jenjang yang tidak dikunci.
    $u1Mda = CoreFixtures::node($this->tenant, 'U1-MDA')->id;
    $this->actingAs($this->admin)
        ->post(route('academic.calendar.assign'), ['target' => 'lembaga', 'organization_id' => $u1Mda, 'calendar_id' => $this->default->id])
        ->assertSessionHasNoErrors();

    expect($resolver->calendarFor($this->tenant->id, $u1Mda)?->id)->toBe($this->default->id);

    // Jenjang MDA dikunci: semua MDA kembali ke Kalender MDA, dan lembaga MDA tidak bisa diatur lain.
    $this->actingAs($this->admin)
        ->post(route('academic.calendar.assign'), ['target' => 'jenjang', 'jenjang' => 'MDA', 'calendar_id' => $mda->id, 'enforced' => '1'])
        ->assertSessionHasNoErrors();

    expect($resolver->calendarFor($this->tenant->id, $u1Mda)?->id)->toBe($mda->id);

    $this->actingAs($this->admin)
        ->post(route('academic.calendar.assign'), ['target' => 'lembaga', 'organization_id' => CoreFixtures::node($this->tenant, 'U2-MDA')->id, 'calendar_id' => $this->default->id])
        ->assertSessionHasErrors(['calendar_id' => 'Aturan ini sudah dikunci oleh tingkat di atasnya, sehingga tidak bisa diubah di sini.']);

    $this->actingAs($this->admin)
        ->get(route('academic.calendar.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('calendars', 2)
            ->has('rules', 2)
            ->where('rules', fn ($rules) => collect($rules)->contains(fn (array $rule): bool => $rule['target'] === 'Semua MDA' && $rule['calendar'] === 'Kalender MDA' && $rule['enforced']))
            ->where('usage', fn ($usage) => collect($usage)->where('calendar', 'Kalender MDA')->count() === 3
                && collect($usage)->where('source', 'Dikunci')->count() === 3),
        );

    // Perubahan aturan dicatat Core beserta nilai lama & baru.
    expect(DB::table('audit_logs')->where('action', AuditAction::SETTING_CHANGED)->where('changes->key', CalendarSetting::KEY)->count())->toBe(3);
});

test('isian pemakaian kalender yang salah ditolak', function () {
    $url = route('academic.calendar.assign');

    $this->actingAs($this->admin)->post($url, ['target' => 'jenjang', 'calendar_id' => $this->default->id])
        ->assertSessionHasErrors(['jenjang' => 'Pilih jenjang.']);
    $this->actingAs($this->admin)->post($url, ['target' => 'lembaga', 'calendar_id' => $this->default->id])
        ->assertSessionHasErrors(['organization_id' => 'Pilih lembaga atau unit.']);
    $this->actingAs($this->admin)->post($url, ['target' => 'jenjang', 'jenjang' => 'SD', 'calendar_id' => $this->default->id])
        ->assertSessionHasErrors('jenjang');
    $this->actingAs($this->admin)->post($url, ['target' => 'semua', 'calendar_id' => $this->default->id])
        ->assertSessionHasErrors('target');

    // Kalender milik yayasan lain.
    $kalenderLain = app(ManageCalendars::class)->ensureDefault(CoreFixtures::tenant('YYS-LAIN')->id);
    $this->actingAs($this->admin)->post($url, ['target' => 'jenjang', 'jenjang' => 'MDA', 'calendar_id' => $kalenderLain->id])
        ->assertSessionHasErrors('calendar_id');

    // Lembaga milik yayasan lain atau ID asal-asalan.
    $this->actingAs($this->admin)
        ->post($url, ['target' => 'lembaga', 'organization_id' => '01900000-0000-7000-8000-000000000000', 'calendar_id' => $this->default->id])
        ->assertNotFound();

    expect(DB::table('scoped_settings')->where('key', CalendarSetting::KEY)->count())->toBe(0);
});
