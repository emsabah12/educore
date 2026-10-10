<?php

namespace Modules\Core\Application\Settings;

/**
 * Aturan "siapa yang menang" untuk scoped settings (PRD-000 §8.2, §8.3). Murni logika, tanpa database.
 *
 * Istilah:
 *   jalur    = node itu sendiri + semua induknya, masing-masing dengan level (1 = langsung di bawah Yayasan)
 *              dan jenjang-nya. Tingkat Yayasan (organization_id kosong) = level 0.
 *   berlaku  = aturan umum di node pada jalur (atau di Yayasan) selalu berlaku;
 *              aturan khusus jenjang J di node X berlaku bila jalur memuat node berjenjang J
 *              yang berada di X atau di bawah X (sama dengan cakupan penugasan fungsional, §7.1).
 *   spesifik = di tingkat yang sama, aturan khusus jenjang lebih spesifik daripada aturan umum;
 *              antar aturan jenjang, yang node jenjang-nya lebih dekat ke node tujuan yang menang [ASUMSI].
 *
 * Urutan resolusi:
 *   1. Aturan terkunci (is_enforced) di tingkat TERATAS → dipakai.
 *   2. Bila tidak ada yang dikunci: aturan di tingkat TERDEKAT.
 *   3. Bila tidak ada sama sekali: default dari modul.
 *
 * @phpstan-type PathNode array{id: string, level: int, jenjang: string|null}
 * @phpstan-type SettingRow array{id: string, organization_id: string|null, jenjang: string|null, is_enforced: bool}
 */
final class SettingPrecedence
{
    public const SOURCE_ENFORCED = 'enforced';

    public const SOURCE_NEAREST = 'nearest';

    public const SOURCE_DEFAULT = 'default';

    /**
     * @param  list<PathNode>  $path
     * @param  list<SettingRow>  $rows
     * @return array{row: SettingRow|null, source: string}
     */
    public function pick(array $path, array $rows): array
    {
        $enforced = null;
        $nearest = null;

        foreach ($rows as $row) {
            $rank = $this->rank($path, $row);

            if ($rank === null) {
                continue;
            }

            [$level, $specificity] = $rank;

            if ($row['is_enforced']) {
                // Tingkat teratas menang; di tingkat sama, yang lebih spesifik.
                if ($enforced === null || $level < $enforced[1] || ($level === $enforced[1] && $specificity > $enforced[2])) {
                    $enforced = [$row, $level, $specificity];
                }
            }

            // Tingkat terdekat menang; di tingkat sama, yang lebih spesifik.
            if ($nearest === null || $level > $nearest[1] || ($level === $nearest[1] && $specificity > $nearest[2])) {
                $nearest = [$row, $level, $specificity];
            }
        }

        if ($enforced !== null) {
            return ['row' => $enforced[0], 'source' => self::SOURCE_ENFORCED];
        }

        if ($nearest !== null) {
            return ['row' => $nearest[0], 'source' => self::SOURCE_NEAREST];
        }

        return ['row' => null, 'source' => self::SOURCE_DEFAULT];
    }

    /**
     * Apakah menulis aturan di target (node X, jenjang J) percuma karena sudah dikunci dari atas?
     * $path = jalur node X (kosong bila X = tingkat Yayasan).
     *
     * Dikunci bila ada aturan terkunci yang berlaku untuk target di tingkat yang LEBIH ATAS.
     * Aturan terkunci di tingkat yang sama tidak mengunci, karena di tingkat yang sama aturan
     * khusus jenjang lebih spesifik (konsisten dengan pick()).
     *
     * @param  list<PathNode>  $path
     * @param  list<SettingRow>  $rows
     */
    public function isLockedFromAbove(array $path, ?string $targetNodeId, ?string $targetJenjang, array $rows): bool
    {
        $targetLevel = $targetNodeId === null ? 0 : $this->levelOf($path, $targetNodeId);

        if ($targetLevel === null) {
            return false;
        }

        foreach ($rows as $row) {
            if (! $row['is_enforced']) {
                continue;
            }

            if ($row['organization_id'] === $targetNodeId && $row['jenjang'] === $targetJenjang) {
                continue; // aturan target itu sendiri
            }

            $rowLevel = $row['organization_id'] === null ? 0 : $this->levelOf($path, $row['organization_id']);

            if ($rowLevel === null) {
                continue; // bukan induk target
            }

            $appliesToTarget = $this->rank($path, $row) !== null
                || ($targetJenjang !== null && $row['jenjang'] === $targetJenjang);

            if (! $appliesToTarget) {
                continue;
            }

            if ($rowLevel < $targetLevel) {
                return true;
            }
        }

        return false;
    }

    /**
     * [tingkat aturan, tingkat spesifik] bila aturan berlaku untuk node di ujung jalur; null bila tidak.
     *
     * @param  list<PathNode>  $path
     * @param  SettingRow  $row
     * @return array{0: int, 1: int}|null
     */
    private function rank(array $path, array $row): ?array
    {
        $level = $row['organization_id'] === null ? 0 : $this->levelOf($path, $row['organization_id']);

        if ($level === null) {
            return null;
        }

        if ($row['jenjang'] === null) {
            return [$level, 0];
        }

        $deepestMatch = null;

        foreach ($path as $node) {
            if ($node['jenjang'] === $row['jenjang'] && $node['level'] >= $level) {
                $deepestMatch = max($deepestMatch ?? 0, $node['level']);
            }
        }

        return $deepestMatch === null ? null : [$level, 1 + $deepestMatch];
    }

    /**
     * @param  list<PathNode>  $path
     */
    private function levelOf(array $path, string $nodeId): ?int
    {
        foreach ($path as $node) {
            if ($node['id'] === $nodeId) {
                return $node['level'];
            }
        }

        return null;
    }
}
