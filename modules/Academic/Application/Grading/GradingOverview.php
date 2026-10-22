<?php

namespace Modules\Academic\Application\Grading;

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
 * Bagian "Aturan penilaian" di halaman Kalender akademik (PRD-002 §19 AC-5a): aturan tersimpan,
 * aturan yang berlaku di tiap lembaga aktif, dan penyimpanannya. Hanya Admin Yayasan (dicek
 * pemanggil, sama dengan Mode presensi); hak akses, kunci dari atas, dan audit tetap lewat Core.
 *
 * @phpstan-import-type Component from GradingRules
 *
 * @phpstan-type GradingRuleRow array{id: string, target: string, summary: string, enforced: bool, value: array{kkm: int, grade_a: int, grade_b: int, components: list<Component>}}
 * @phpstan-type GradingUsage array{id: string, name: string, summary: string, source: string}
 */
final class GradingOverview
{
    public function __construct(
        private readonly ScopedSettingResolver $settings,
        private readonly SetScopedSetting $setScopedSetting,
    ) {}

    /**
     * @return array{rules: list<GradingRuleRow>, usage: list<GradingUsage>, defaults: array{kkm: int, grade_a: int, grade_b: int, components: list<Component>}, maxComponents: int}
     */
    public function build(string $tenantId): array
    {
        return [
            'rules' => $this->rules($tenantId),
            'usage' => $this->usage($tenantId),
            'defaults' => GradingRules::defaults()->toArray(),
            'maxComponents' => GradingRules::MAX_COMPONENTS,
        ];
    }

    /**
     * Target: jenjang terisi & lembaga kosong → semua lembaga berjenjang itu; lembaga terisi → lembaga/unit itu.
     *
     * @throws AuthorizationException
     * @throws SettingException
     */
    public function assign(?string $organizationId, ?Jenjang $jenjang, GradingRules $rules, bool $enforced): ScopedSetting
    {
        return $this->setScopedSetting->handle(GradingSetting::KEY, $organizationId, $jenjang, $rules->toArray(), $enforced);
    }

    /**
     * @return list<GradingRuleRow>
     */
    private function rules(string $tenantId): array
    {
        $settings = ScopedSetting::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('key', GradingSetting::KEY)
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

            $rules = GradingRules::fromValue($setting->value);

            $rows[] = [
                'id' => $setting->id,
                'target' => $target,
                'summary' => $rules->summary(),
                'enforced' => $setting->is_enforced,
                'value' => $rules->toArray(),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['target'], $b['target']));

        return $rows;
    }

    /**
     * @return list<GradingUsage>
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
            $resolved = $this->settings->resolve($tenantId, GradingSetting::KEY, $organization->id);

            $rows[] = [
                'id' => $organization->id,
                'name' => $organization->name,
                'summary' => GradingRules::fromValue($resolved->value)->summary(),
                'source' => match ($resolved->source) {
                    SettingPrecedence::SOURCE_ENFORCED => 'Dikunci',
                    SettingPrecedence::SOURCE_NEAREST => 'Diatur',
                    default => 'Bawaan',
                },
            ];
        }

        return $rows;
    }
}
