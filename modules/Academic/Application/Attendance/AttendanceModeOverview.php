<?php

namespace Modules\Academic\Application\Attendance;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SetScopedSetting;
use Modules\Core\Application\Settings\SettingPrecedence;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Settings\Exceptions\SettingException;
use Modules\Core\Domain\Settings\ScopedSetting;

/**
 * Bagian "Mode presensi" di halaman Kalender akademik (PRD-002 §17 AC-4c): aturan tersimpan, mode
 * yang berlaku di tiap lembaga aktif, dan penyimpanannya. Hanya Admin Yayasan [OWNER, 2026-10-21]
 * (dicek pemanggil); hak akses, kunci dari atas, dan audit nilai lama & baru tetap lewat Core.
 *
 * @phpstan-type ModeRule array{id: string, target: string, mode: string, enforced: bool}
 * @phpstan-type ModeUsage array{id: string, name: string, mode: string, source: string}
 */
final class AttendanceModeOverview
{
    public function __construct(
        private readonly ScopedSettingResolver $settings,
        private readonly SetScopedSetting $setScopedSetting,
    ) {}

    /**
     * @return array{rules: list<ModeRule>, usage: list<ModeUsage>, modes: list<array{value: string, label: string}>}
     */
    public function build(string $tenantId): array
    {
        return [
            'rules' => $this->rules($tenantId),
            'usage' => $this->usage($tenantId),
            'modes' => [
                ['value' => AttendanceModeSetting::DAILY, 'label' => AttendanceModeSetting::label(AttendanceModeSetting::DAILY)],
                ['value' => AttendanceModeSetting::LESSON, 'label' => AttendanceModeSetting::label(AttendanceModeSetting::LESSON)],
            ],
        ];
    }

    /**
     * Target: jenjang terisi & lembaga kosong → semua lembaga berjenjang itu; lembaga terisi → lembaga/unit itu.
     *
     * @throws AuthorizationException
     * @throws SettingException
     */
    public function assign(?string $organizationId, ?Jenjang $jenjang, string $mode, bool $enforced): ScopedSetting
    {
        return $this->setScopedSetting->handle(AttendanceModeSetting::KEY, $organizationId, $jenjang, $mode, $enforced);
    }

    /**
     * @return list<ModeRule>
     */
    private function rules(string $tenantId): array
    {
        $settings = ScopedSetting::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('key', AttendanceModeSetting::KEY)
            ->get();

        if ($settings->isEmpty()) {
            return [];
        }

        $nodeNames = [];

        foreach (Organization::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereIn('id', $settings->pluck('organization_id')->filter())->get() as $node) {
            $nodeNames[$node->id] = $node->name;
        }

        $rows = [];

        foreach ($settings as $setting) {
            $node = $setting->organization_id === null ? null : ($nodeNames[$setting->organization_id] ?? 'Lembaga tidak dikenal');
            $jenjang = $setting->jenjang?->label();

            if ($node === null) {
                $target = $jenjang === null ? 'Seluruh yayasan' : "Semua {$jenjang}";
            } else {
                $target = $jenjang === null ? $node : "{$jenjang} di {$node}";
            }

            $rows[] = [
                'id' => $setting->id,
                'target' => $target,
                'mode' => AttendanceModeSetting::label(is_string($setting->value) ? $setting->value : AttendanceModeSetting::DAILY),
                'enforced' => $setting->is_enforced,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['target'], $b['target']));

        return $rows;
    }

    /**
     * @return list<ModeUsage>
     */
    private function usage(string $tenantId): array
    {
        $lembaga = Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($lembaga as $organization) {
            $resolved = $this->settings->resolve($tenantId, AttendanceModeSetting::KEY, $organization->id);

            $source = match ($resolved->source) {
                SettingPrecedence::SOURCE_ENFORCED => 'Dikunci',
                SettingPrecedence::SOURCE_NEAREST => 'Diatur',
                default => 'Bawaan',
            };

            $rows[] = [
                'id' => $organization->id,
                'name' => $organization->name,
                'mode' => AttendanceModeSetting::label($resolved->value === AttendanceModeSetting::LESSON ? AttendanceModeSetting::LESSON : AttendanceModeSetting::DAILY),
                'source' => $source,
            ];
        }

        return $rows;
    }
}
