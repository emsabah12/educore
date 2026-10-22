<?php

use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Grading\GradingRules;
use Modules\Academic\Application\Grading\GradingSetting;
use Modules\Academic\Database\Seeders\AcademicCalendarSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Aturan penilaian `academic.grading` (PRD-002 §19 AC-5a): KKM, komponen & bobot, batas predikat
 * per jenjang/lembaga, diatur Admin Yayasan di halaman Kalender akademik.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicCalendarSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function atnForm(array $overrides = []): array
{
    return array_merge([
        'target' => 'jenjang', 'jenjang' => 'MA', 'kkm' => '70', 'grade_a' => '88', 'grade_b' => '78',
        'components' => [
            ['code' => 'tugas', 'name' => '  Tugas   harian ', 'weight' => '30'],
            ['code' => 'PTS', 'name' => 'PTS', 'weight' => '30'],
            ['code' => 'PAS', 'name' => 'PAS', 'weight' => '40'],
        ],
    ], $overrides);
}

test('bawaan: KKM 75, Harian 40 / PTS 30 / PAS 30, A ≥ 90, B ≥ 80; predikat dihitung dari aturan', function () {
    $rules = app(GradingSetting::class)->rulesFor($this->tenant->id, $this->ma);

    expect($rules->toArray())->toBe(GradingRules::defaults()->toArray())
        ->and($rules->kkm)->toBe(75)
        ->and([$rules->predicate(90), $rules->predicate(89), $rules->predicate(80), $rules->predicate(75), $rules->predicate(74)])->toBe(['A', 'B', 'B', 'C', 'D'])
        ->and(GradingRules::fromValue(['kkm' => 'rusak']))->toEqual(GradingRules::defaults());

    $this->actingAs($this->admin)
        ->get(route('academic.calendar.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('grading.rules', [])
            ->where('grading.maxComponents', 6)
            ->where('grading.usage', fn ($usage) => collect($usage)->contains(fn (array $row): bool => $row['id'] === $this->ma
                && $row['source'] === 'Bawaan'
                && $row['summary'] === 'KKM 75 · A ≥ 90, B ≥ 80 · Nilai harian 40%, Penilaian tengah semester 30%, Penilaian akhir semester 30%')),
        );
});

test('Admin Yayasan mengatur aturan untuk jenjang MA: berlaku di MA, lembaga lain tetap bawaan', function () {
    $this->actingAs($this->admin)->post(route('academic.grading.assign'), atnForm())->assertSessionHasNoErrors();

    $ma = app(GradingSetting::class)->rulesFor($this->tenant->id, $this->ma);

    expect($ma->toArray())->toBe([
        'kkm' => 70, 'grade_a' => 88, 'grade_b' => 78,
        'components' => [
            ['code' => 'TUGAS', 'name' => 'Tugas harian', 'weight' => 30],
            ['code' => 'PTS', 'name' => 'PTS', 'weight' => 30],
            ['code' => 'PAS', 'name' => 'PAS', 'weight' => 40],
        ],
    ])
        ->and($ma->predicate(78))->toBe('B')
        ->and(app(GradingSetting::class)->rulesFor($this->tenant->id, $this->smp)->kkm)->toBe(75);

    $this->actingAs($this->admin)
        ->get(route('academic.calendar.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('grading.rules.0.target', 'Semua MA')
            ->where('grading.rules.0.summary', 'KKM 70 · A ≥ 88, B ≥ 78 · Tugas harian 30%, PTS 30%, PAS 40%')
            ->where('grading.rules.0.enforced', false)
            ->where('grading.usage', fn ($usage) => collect($usage)->contains(fn (array $row): bool => $row['id'] === $this->ma && $row['source'] === 'Diatur')),
        );
});

test('ditolak: bobot tidak 100, kode ganda, batas predikat tidak berurutan, terlalu banyak komponen; selain Admin Yayasan 403', function () {
    $assign = fn (array $overrides) => $this->actingAs($this->admin)->post(route('academic.grading.assign'), atnForm($overrides));

    $assign(['components' => [['code' => 'A', 'name' => 'A', 'weight' => '50'], ['code' => 'B', 'name' => 'B', 'weight' => '40']]])
        ->assertSessionHasErrors(['components' => 'Jumlah bobot komponen harus 100 (sekarang 90).']);
    $assign(['components' => [['code' => 'PTS', 'name' => 'A', 'weight' => '50'], ['code' => 'pts', 'name' => 'B', 'weight' => '50']]])
        ->assertSessionHasErrors(['components' => 'Kode komponen PTS dipakai lebih dari sekali.']);
    $assign(['components' => [['code' => 'PT S', 'name' => 'A', 'weight' => '100']]])
        ->assertSessionHasErrors(['components' => 'Kode komponen ke-1: huruf besar, angka, atau garis bawah, maksimal 10 karakter.']);
    $assign(['grade_b' => '65'])
        ->assertSessionHasErrors(['components' => 'Batas predikat harus berurutan: batas A lebih tinggi dari batas B, dan batas B lebih tinggi dari KKM.']);
    $assign(['components' => array_fill(0, 7, ['code' => 'X', 'name' => 'X', 'weight' => '10'])])
        ->assertSessionHasErrors(['components' => 'Paling banyak 6 komponen nilai.']);

    expect(app(GradingSetting::class)->rulesFor($this->tenant->id, $this->ma)->kkm)->toBe(75);

    $this->actingAs(DevAccounts::user('kepala.unit1'))->post(route('academic.grading.assign'), atnForm())->assertForbidden();
});
