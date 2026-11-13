<?php

namespace Modules\HR\Application\Performance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Performance\Exceptions\PerformanceException;
use Modules\HR\Domain\Performance\PerformanceGroup;

/**
 * Komponen & bobot penilaian kinerja per yayasan, terpisah guru dan staf (PRD-001 §19.2, HR-8b).
 *
 * - Selama yayasan belum menyimpan komponennya, dipakai isi bawaan (4 komponen × 25%).
 * - Simpan = mengganti seluruh daftar kelompok itu: 1–10 komponen, nama 2–100 karakter dan tidak kembar, bobot bilangan
 *   bulat 1–100, jumlah bobot tepat 100.
 * - Penilaian menyalin komponen saat dinilai, jadi mengubah komponen tidak mengubah penilaian yang sudah tersimpan.
 *
 * Hak akses dicek pemanggil (controller).
 */
final class PerformanceComponents
{
    public const MAX_COMPONENTS = 10;

    public const NAME_MIN = 2;

    public const NAME_MAX = 100;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<array{name: string, weight: int}>
     */
    public function forGroup(string $tenantId, PerformanceGroup $group): array
    {
        $rows = DB::table('hr_performance_components')
            ->where('tenant_id', $tenantId)
            ->where('group', $group->value)
            ->orderBy('position')
            ->get(['name', 'weight']);

        if ($rows->isEmpty()) {
            return $group->defaults();
        }

        $components = [];

        foreach ($rows as $row) {
            $components[] = ['name' => (string) $row->name, 'weight' => (int) $row->weight];
        }

        return $components;
    }

    /**
     * @return array<string, list<array{name: string, weight: int}>> kode kelompok → komponen
     */
    public function all(string $tenantId): array
    {
        $all = [];

        foreach (PerformanceGroup::cases() as $group) {
            $all[$group->value] = $this->forGroup($tenantId, $group);
        }

        return $all;
    }

    /**
     * @param  array<mixed>  $rows  baris form: `[['name' => …, 'weight' => …], …]`
     *
     * @throws PerformanceException
     */
    public function save(string $tenantId, PerformanceGroup $group, array $rows): void
    {
        $components = $this->validate($rows);

        DB::transaction(function () use ($tenantId, $group, $components): void {
            DB::table('hr_performance_components')->where('tenant_id', $tenantId)->where('group', $group->value)->delete();

            foreach ($components as $position => $component) {
                DB::table('hr_performance_components')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'group' => $group->value,
                    'name' => $component['name'],
                    'weight' => $component['weight'],
                    'position' => $position + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record(
                HrAuditAction::PERFORMANCE_COMPONENTS_UPDATED,
                HrAuditAction::SUBJECT_PERFORMANCE_COMPONENTS,
                $group->value,
                ['kelompok' => $group->value, 'jumlah_komponen' => count($components)],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<array{name: string, weight: int}>
     *
     * @throws PerformanceException
     */
    private function validate(array $rows): array
    {
        $components = [];
        $names = [];
        $total = 0;

        foreach (array_values($rows) as $index => $row) {
            $number = $index + 1;
            $row = is_array($row) ? $row : [];
            $name = trim((string) preg_replace('/\s+/u', ' ', is_scalar($row['name'] ?? null) ? (string) $row['name'] : ''));
            $weight = is_scalar($row['weight'] ?? null) ? trim((string) $row['weight']) : '';

            if ($name === '' && $weight === '') {
                continue; // Baris kosong di form diabaikan.
            }

            $length = mb_strlen($name);

            if ($length < self::NAME_MIN || $length > self::NAME_MAX) {
                throw PerformanceException::make('components', "Nama komponen baris {$number} harus ".self::NAME_MIN.'–'.self::NAME_MAX.' karakter.');
            }

            $key = mb_strtolower($name);

            if (isset($names[$key])) {
                throw PerformanceException::make('components', "Komponen \"{$name}\" ditulis dua kali.");
            }

            if (preg_match('/^\d{1,3}$/', $weight) !== 1 || (int) $weight < 1 || (int) $weight > 100) {
                throw PerformanceException::make('components', "Bobot \"{$name}\" harus bilangan bulat 1–100.");
            }

            $names[$key] = true;
            $total += (int) $weight;
            $components[] = ['name' => $name, 'weight' => (int) $weight];
        }

        if ($components === []) {
            throw PerformanceException::make('components', 'Isi minimal satu komponen.');
        }

        if (count($components) > self::MAX_COMPONENTS) {
            throw PerformanceException::make('components', 'Paling banyak '.self::MAX_COMPONENTS.' komponen.');
        }

        if ($total !== 100) {
            throw PerformanceException::make('components', "Jumlah bobot harus 100%, sekarang {$total}%.");
        }

        return $components;
    }
}
