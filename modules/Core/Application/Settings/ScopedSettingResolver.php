<?php

namespace Modules\Core\Application\Settings;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Domain\Settings\Exceptions\SettingException;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Membaca nilai aturan berjenjang yang berlaku di sebuah node (PRD-000 §8.2, §8.3).
 *
 * Contoh pemakaian di modul:
 *
 *   $jamMasuk = $resolver->current('hr.attendance.check_in_time', $employee->organization_id)->value;
 *
 * Membaca aturan tidak dibatasi hak akses: aturan berlaku untuk siapa pun yang bekerja di node itu.
 * Yang dibatasi adalah MENGUBAH aturan (SetScopedSetting).
 *
 * @phpstan-import-type PathNode from SettingPrecedence
 * @phpstan-import-type SettingRow from SettingPrecedence
 */
final class ScopedSettingResolver
{
    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly SettingPrecedence $precedence,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Nilai aturan di yayasan aktif. Pakai ini di request web agar tidak pernah
     * tertukar yayasan; resolve() dengan tenant eksplisit untuk konsol/job.
     *
     * @throws SettingException
     */
    public function current(string $key, ?string $organizationId): ResolvedSetting
    {
        return $this->resolve($this->tenantContext->require()->tenantId, $key, $organizationId);
    }

    /**
     * @param  string|null  $organizationId  null = tingkat Yayasan
     *
     * @throws SettingException
     */
    public function resolve(string $tenantId, string $key, ?string $organizationId): ResolvedSetting
    {
        $definition = $this->registry->get($key);
        $path = $this->pathOf($tenantId, $organizationId);
        $rows = $this->rows($tenantId, $key, $path);

        $picked = $this->precedence->pick($path, array_column($rows, 'meta'));

        if ($picked['row'] === null) {
            return new ResolvedSetting($key, $definition->default, SettingPrecedence::SOURCE_DEFAULT, null);
        }

        $settingId = $picked['row']['id'];

        return new ResolvedSetting($key, $rows[$settingId]['value'], $picked['source'], $settingId);
    }

    /**
     * Jalur node: dirinya + semua induknya, dengan level (1 = langsung di bawah Yayasan) dan jenjang.
     * Kosong untuk tingkat Yayasan.
     *
     * @return list<PathNode>
     *
     * @throws SettingException
     */
    public function pathOf(string $tenantId, ?string $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        if (! Str::isUuid($organizationId)) {
            throw SettingException::organizationNotFound();
        }

        $ancestors = DB::table('organization_closure as c')
            ->join('organizations as o', function (JoinClause $join): void {
                $join->on('o.tenant_id', '=', 'c.tenant_id')
                    ->on('o.id', '=', 'c.ancestor_id');
            })
            ->where('c.tenant_id', $tenantId)
            ->where('c.descendant_id', $organizationId)
            ->select(['c.ancestor_id', 'c.depth', 'o.jenjang'])
            ->get();

        if ($ancestors->isEmpty()) {
            throw SettingException::organizationNotFound();
        }

        $deepest = (int) $ancestors->max('depth');
        $path = [];

        foreach ($ancestors as $ancestor) {
            $path[] = [
                'id' => (string) $ancestor->ancestor_id,
                'level' => $deepest - (int) $ancestor->depth + 1,
                'jenjang' => $ancestor->jenjang === null ? null : (string) $ancestor->jenjang,
            ];
        }

        usort($path, fn (array $a, array $b): int => $a['level'] <=> $b['level']);

        return $path;
    }

    /**
     * Semua nilai aturan $key di tingkat Yayasan dan di node-node pada jalur.
     *
     * @param  list<PathNode>  $path
     * @return array<string, array{meta: SettingRow, value: mixed}>
     */
    public function rows(string $tenantId, string $key, array $path): array
    {
        $nodeIds = array_column($path, 'id');

        $records = DB::table('scoped_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->where(function ($query) use ($nodeIds): void {
                $query->whereNull('organization_id');

                if ($nodeIds !== []) {
                    $query->orWhereIn('organization_id', $nodeIds);
                }
            })
            ->select(['id', 'organization_id', 'jenjang', 'value', 'is_enforced'])
            ->get();

        $rows = [];

        foreach ($records as $record) {
            $id = (string) $record->id;

            $rows[$id] = [
                'meta' => [
                    'id' => $id,
                    'organization_id' => $record->organization_id === null ? null : (string) $record->organization_id,
                    'jenjang' => $record->jenjang === null ? null : (string) $record->jenjang,
                    'is_enforced' => (bool) $record->is_enforced,
                ],
                'value' => json_decode((string) $record->value, true),
            ];
        }

        return $rows;
    }
}
