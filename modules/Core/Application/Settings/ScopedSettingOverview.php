<?php

namespace Modules\Core\Application\Settings;

use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Settings\ScopedSetting;

/**
 * Data tampilan aturan berjenjang (PRD-000 §8) untuk halaman pengaturan modul: aturan tersimpan per
 * key, nilai yang berlaku di tiap lembaga, dan pilihan target (jenjang, lembaga/unit). Dipindah dari
 * HR (LeaveSettingsOverview) agar bisa dipakai modul lain (Finance, PRD-003). Hak akses dicek pemanggil.
 *
 * @phpstan-type RuleRow array{id: string, target: string, days: string, enforced: bool}
 * @phpstan-type UsageRow array{id: string, name: string, days: string, source: string}
 * @phpstan-type OptionRow array{value: string, label: string}
 */
final class ScopedSettingOverview
{
    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    /**
     * Aturan tersimpan untuk sebuah key, urut nama target. `days` = label nilai (nama lama dipertahankan).
     *
     * @param  callable(mixed): string  $label
     * @return list<RuleRow>
     */
    public function rules(string $tenantId, string $key, callable $label): array
    {
        $settings = ScopedSetting::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('key', $key)->get();

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
                'days' => $label($setting->value),
                'enforced' => $setting->is_enforced,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['target'], $b['target']));

        return $rows;
    }

    /**
     * Nilai yang berlaku di tiap lembaga aktif (node LEMBAGA), beserta sumbernya.
     *
     * @param  callable(mixed): string  $label
     * @return list<UsageRow>
     */
    public function usage(string $tenantId, string $key, callable $label): array
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
            $resolved = $this->settings->resolve($tenantId, $key, $organization->id);

            $rows[] = [
                'id' => $organization->id,
                'name' => $organization->name,
                'days' => $label($resolved->value),
                'source' => match ($resolved->source) {
                    SettingPrecedence::SOURCE_ENFORCED => 'Dikunci',
                    SettingPrecedence::SOURCE_NEAREST => 'Diatur',
                    default => 'Bawaan',
                },
            ];
        }

        return $rows;
    }

    /**
     * Pilihan target aturan: jenjang & lembaga/unit aktif.
     *
     * @return array{jenjang: list<OptionRow>, organizations: list<OptionRow>}
     */
    public function targetOptions(string $tenantId): array
    {
        $jenjang = [];

        foreach (Jenjang::cases() as $case) {
            $jenjang[] = ['value' => $case->value, 'label' => $case->label()];
        }

        $organizations = [];

        foreach (Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('type', [OrganizationType::Lembaga->value, OrganizationType::Unit->value])
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get() as $organization) {
            $organizations[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return ['jenjang' => $jenjang, 'organizations' => $organizations];
    }
}
