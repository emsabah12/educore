<?php

namespace Modules\HR\Application\Leave;

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
use Modules\HR\Domain\Leave\Holiday;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Data halaman Pengaturan cuti (PRD-001 §13.2–§13.4): jenis cuti, libur mingguan & alur persetujuan per
 * jenjang/lembaga (aturan berjenjang), dan hari libur yayasan per tahun. Hak akses dicek pemanggil.
 *
 * @phpstan-type TypeRow array{id: string, code: string, name: string, has_quota: bool, annual_quota: int|null, max_days: int|null, is_active: bool}
 * @phpstan-type RuleRow array{id: string, target: string, days: string, enforced: bool}
 * @phpstan-type UsageRow array{id: string, name: string, days: string, source: string}
 * @phpstan-type HolidayRow array{id: string, name: string, start_date: string, end_date: string, days: int}
 * @phpstan-type OptionRow array{value: string, label: string}
 */
final class LeaveSettingsOverview
{
    public function __construct(
        private readonly ScopedSettingResolver $settings,
        private readonly SetScopedSetting $setScopedSetting,
    ) {}

    /**
     * @return array{
     *     types: list<TypeRow>,
     *     weeklyOff: array{rules: list<RuleRow>, usage: list<UsageRow>},
     *     approval: array{rules: list<RuleRow>, usage: list<UsageRow>},
     *     holidays: list<HolidayRow>,
     *     dayOptions: list<OptionRow>,
     *     options: array{jenjang: list<OptionRow>, organizations: list<OptionRow>},
     * }
     */
    public function build(string $tenantId, int $year): array
    {
        $types = [];

        $rows = LeaveType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->orderByDesc('is_active')->orderBy('name')->get();

        foreach ($rows as $type) {
            $types[] = [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'has_quota' => $type->has_quota,
                'annual_quota' => $type->annual_quota,
                'max_days' => $type->max_days,
                'is_active' => $type->is_active,
            ];
        }

        $holidays = [];

        $rows = Holiday::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('start_date', '<=', "{$year}-12-31")
            ->where('end_date', '>=', "{$year}-01-01")
            ->orderBy('start_date')
            ->orderBy('name')
            ->get();

        foreach ($rows as $holiday) {
            $holidays[] = [
                'id' => $holiday->id,
                'name' => $holiday->name,
                'start_date' => $holiday->start_date->toDateString(),
                'end_date' => $holiday->end_date->toDateString(),
                'days' => $holiday->length(),
            ];
        }

        $dayOptions = [];

        foreach (WeeklyOffSetting::DAYS as $day => $label) {
            $dayOptions[] = ['value' => (string) $day, 'label' => $label];
        }

        $jenjang = [];

        foreach (Jenjang::cases() as $case) {
            $jenjang[] = ['value' => $case->value, 'label' => $case->label()];
        }

        return [
            'types' => $types,
            'weeklyOff' => [
                'rules' => $this->rules($tenantId, WeeklyOffSetting::KEY, fn (mixed $value): string => WeeklyOffSetting::label(WeeklyOffSetting::normalize($value))),
                'usage' => $this->usage($tenantId, WeeklyOffSetting::KEY, fn (mixed $value): string => WeeklyOffSetting::label(WeeklyOffSetting::normalize($value))),
            ],
            // Alur persetujuan cuti per jenjang/lembaga (HR-3c).
            'approval' => [
                'rules' => $this->rules($tenantId, LeaveApprovalSetting::KEY, fn (mixed $value): string => LeaveApprovalSetting::label($value === 1 ? 1 : 2)),
                'usage' => $this->usage($tenantId, LeaveApprovalSetting::KEY, fn (mixed $value): string => LeaveApprovalSetting::label($value === 1 ? 1 : 2)),
            ],
            'holidays' => $holidays,
            'dayOptions' => $dayOptions,
            'options' => ['jenjang' => $jenjang, 'organizations' => $this->organizationOptions($tenantId)],
        ];
    }

    /**
     * Target: jenjang terisi & lembaga kosong → semua lembaga berjenjang itu; lembaga terisi → lembaga/unit itu.
     *
     * @param  list<int>  $days
     *
     * @throws AuthorizationException
     * @throws SettingException
     */
    public function assignWeeklyOff(?string $organizationId, ?Jenjang $jenjang, array $days, bool $enforced): ScopedSetting
    {
        sort($days);

        return $this->setScopedSetting->handle(WeeklyOffSetting::KEY, $organizationId, $jenjang, array_values(array_unique($days)), $enforced);
    }

    /**
     * Alur persetujuan cuti: 1 tahap atau 2 tahap.
     *
     * @throws AuthorizationException
     * @throws SettingException
     */
    public function assignApproval(?string $organizationId, ?Jenjang $jenjang, int $steps, bool $enforced): ScopedSetting
    {
        return $this->setScopedSetting->handle(LeaveApprovalSetting::KEY, $organizationId, $jenjang, $steps, $enforced);
    }

    /**
     * @param  callable(mixed): string  $label
     * @return list<RuleRow>
     */
    private function rules(string $tenantId, string $key, callable $label): array
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
     * @param  callable(mixed): string  $label
     * @return list<UsageRow>
     */
    private function usage(string $tenantId, string $key, callable $label): array
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
     * @return list<OptionRow>
     */
    private function organizationOptions(string $tenantId): array
    {
        $options = [];

        $organizations = Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('type', [OrganizationType::Lembaga->value, OrganizationType::Unit->value])
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get();

        foreach ($organizations as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }
}
