<?php

namespace Modules\HR\Application\Leave;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Core\Application\Settings\ScopedSettingOverview;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SetScopedSetting;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Settings\Exceptions\SettingException;
use Modules\Core\Domain\Settings\ScopedSetting;
use Modules\HR\Application\Attendance\WorkHoursSetting;
use Modules\HR\Contracts\SchoolWeeklyOff;
use Modules\HR\Domain\Leave\Holiday;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Data halaman Pengaturan cuti (PRD-001 §13.2–§13.4): jenis cuti, libur mingguan & alur persetujuan per
 * jenjang/lembaga (aturan berjenjang), dan hari libur yayasan per tahun. Hak akses dicek pemanggil.
 *
 * @phpstan-type TypeRow array{id: string, code: string, name: string, has_quota: bool, annual_quota: int|null, max_days: int|null, is_active: bool}
 * @phpstan-type RuleRow array{id: string, target: string, days: string, enforced: bool}
 * @phpstan-type UsageRow array{id: string, name: string, days: string, source: string}
 * @phpstan-type WeeklyOffUsageRow array{id: string, name: string, days: string, source: string, academic: string|null, differs: bool}
 * @phpstan-type HolidayRow array{id: string, name: string, start_date: string, end_date: string, days: int}
 * @phpstan-type OptionRow array{value: string, label: string}
 */
final class LeaveSettingsOverview
{
    public function __construct(
        private readonly ScopedSettingResolver $settings,
        private readonly SetScopedSetting $setScopedSetting,
        private readonly SchoolWeeklyOff $schoolWeeklyOff,
        private readonly ScopedSettingOverview $overview,
    ) {}

    /**
     * @return array{
     *     types: list<TypeRow>,
     *     weeklyOff: array{rules: list<RuleRow>, usage: list<WeeklyOffUsageRow>, differs: int},
     *     approval: array{rules: list<RuleRow>, usage: list<UsageRow>},
     *     workHours: array{rules: list<RuleRow>, usage: list<UsageRow>},
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

        $weeklyOffUsage = $this->weeklyOffUsage($tenantId);

        return [
            'types' => $types,
            'weeklyOff' => [
                'rules' => $this->rules($tenantId, WeeklyOffSetting::KEY, fn (mixed $value): string => WeeklyOffSetting::label(WeeklyOffSetting::normalize($value))),
                'usage' => $weeklyOffUsage,
                // Jumlah lembaga yang libur mingguan HR-nya berbeda dengan kalender akademiknya (OD-HR-07).
                'differs' => count(array_filter($weeklyOffUsage, fn (array $row): bool => $row['differs'])),
            ],
            // Alur persetujuan cuti per jenjang/lembaga (HR-3c).
            'approval' => [
                'rules' => $this->rules($tenantId, LeaveApprovalSetting::KEY, fn (mixed $value): string => LeaveApprovalSetting::label($value === 1 ? 1 : 2)),
                'usage' => $this->usage($tenantId, LeaveApprovalSetting::KEY, fn (mixed $value): string => LeaveApprovalSetting::label($value === 1 ? 1 : 2)),
            ],
            // Jam kerja staf per jenjang/lembaga (HR-4a).
            'workHours' => [
                'rules' => $this->rules($tenantId, WorkHoursSetting::KEY, fn (mixed $value): string => WorkHoursSetting::label(WorkHoursSetting::normalize($value))),
                'usage' => $this->usage($tenantId, WorkHoursSetting::KEY, fn (mixed $value): string => WorkHoursSetting::label(WorkHoursSetting::normalize($value))),
            ],
            'holidays' => $holidays,
            'dayOptions' => $dayOptions,
            'options' => $this->overview->targetOptions($tenantId),
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
     * Jam kerja staf: masuk, pulang ("HH:MM"), toleransi terlambat (menit).
     *
     * @throws AuthorizationException
     * @throws SettingException
     */
    public function assignWorkHours(?string $organizationId, ?Jenjang $jenjang, string $start, string $end, int $tolerance, bool $enforced): ScopedSetting
    {
        return $this->setScopedSetting->handle(WorkHoursSetting::KEY, $organizationId, $jenjang, ['start' => $start, 'end' => $end, 'tolerance' => $tolerance], $enforced);
    }

    /**
     * Aturan tersimpan untuk sebuah key (dipakai juga Pengaturan gaji, HR-5b). Lihat ScopedSettingOverview (Core).
     *
     * @param  callable(mixed): string  $label
     * @return list<RuleRow>
     */
    public function rules(string $tenantId, string $key, callable $label): array
    {
        return $this->overview->rules($tenantId, $key, $label);
    }

    /**
     * Libur mingguan HR yang berlaku di tiap lembaga, dibandingkan dengan libur mingguan kalender
     * akademik lembaga itu. Keduanya tetap terpisah (OD-HR-07 [OWNER, 2026-10-23]); perbedaannya
     * hanya ditandai agar Admin SDM sadar.
     *
     * @return list<WeeklyOffUsageRow>
     */
    private function weeklyOffUsage(string $tenantId): array
    {
        $rows = $this->usage($tenantId, WeeklyOffSetting::KEY, fn (mixed $value): string => WeeklyOffSetting::label(WeeklyOffSetting::normalize($value)));
        $school = $this->schoolWeeklyOff->forOrganizations($tenantId, array_column($rows, 'id'));
        $result = [];

        foreach ($rows as $row) {
            $academic = $school[$row['id']] ?? null;
            $hr = WeeklyOffSetting::normalize($this->settings->resolve($tenantId, WeeklyOffSetting::KEY, $row['id'])->value);

            $result[] = $row + [
                'academic' => $academic === null ? null : WeeklyOffSetting::label($academic),
                'differs' => $academic !== null && $academic !== $hr,
            ];
        }

        return $result;
    }

    /**
     * @param  callable(mixed): string  $label
     * @return list<UsageRow>
     */
    public function usage(string $tenantId, string $key, callable $label): array
    {
        return $this->overview->usage($tenantId, $key, $label);
    }

    /**
     * Pilihan target aturan: jenjang & lembaga/unit aktif (dipakai juga Pengaturan gaji, HR-5b).
     *
     * @return array{jenjang: list<OptionRow>, organizations: list<OptionRow>}
     */
    public function targetOptions(string $tenantId): array
    {
        return $this->overview->targetOptions($tenantId);
    }
}
