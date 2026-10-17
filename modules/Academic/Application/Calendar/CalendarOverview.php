<?php

namespace Modules\Academic\Application\Calendar;

use Illuminate\Support\Collection;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
use Modules\Core\Application\Settings\SettingPrecedence;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Settings\ScopedSetting;

/**
 * Data halaman Kalender akademik (PRD-002 §4.4): daftar kalender, tahun ajaran kalender terpilih,
 * aturan pemakaian kalender, dan ringkasan kalender yang berlaku di tiap lembaga aktif.
 *
 * @phpstan-type TermRow array{sequence: int, name: string, start_date: string, end_date: string}
 * @phpstan-type YearRow array{id: string, name: string, start_date: string, end_date: string, is_current: bool, terms: list<TermRow>}
 * @phpstan-type CalendarRow array{id: string, name: string, is_default: bool, years: int, current_year: string|null}
 * @phpstan-type RuleRow array{id: string, target: string, calendar: string, enforced: bool}
 * @phpstan-type UsageRow array{id: string, name: string, calendar: string|null, current_year: string|null, source: string}
 * @phpstan-type OptionRow array{value: string, label: string}
 */
final class CalendarOverview
{
    public function __construct(
        private readonly ManageCalendars $calendars,
        private readonly AcademicCalendarResolver $resolver,
    ) {}

    /**
     * @return array{
     *     calendars: list<CalendarRow>,
     *     selected: array{id: string, name: string, is_default: bool, years: list<YearRow>},
     *     rules: list<RuleRow>,
     *     usage: list<UsageRow>,
     *     options: array{jenjang: list<OptionRow>, organizations: list<OptionRow>},
     * }
     */
    public function build(string $tenantId, ?string $selectedCalendarId): array
    {
        $default = $this->calendars->ensureDefault($tenantId);

        $calendars = AcademicCalendar::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $selected = $calendars->firstWhere('id', $selectedCalendarId) ?? $default;

        $years = AcademicYear::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('start_date')
            ->get();

        $calendarRows = [];
        $calendarNames = [];

        foreach ($calendars as $calendar) {
            $own = $years->where('calendar_id', $calendar->id);
            $current = $own->firstWhere('is_current', true);
            $calendarNames[$calendar->id] = $calendar->name;

            $calendarRows[] = [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'is_default' => $calendar->is_default,
                'years' => $own->count(),
                'current_year' => $current instanceof AcademicYear ? $current->name : null,
            ];
        }

        $jenjangOptions = [];

        foreach (Jenjang::cases() as $jenjang) {
            $jenjangOptions[] = ['value' => $jenjang->value, 'label' => $jenjang->label()];
        }

        return [
            'calendars' => $calendarRows,
            'selected' => [
                'id' => $selected->id,
                'name' => $selected->name,
                'is_default' => $selected->is_default,
                'years' => $this->yearRows($years->where('calendar_id', $selected->id)),
            ],
            'rules' => $this->rules($tenantId, $calendarNames),
            'usage' => $this->usage($tenantId),
            'options' => [
                'jenjang' => $jenjangOptions,
                'organizations' => $this->organizationOptions($tenantId),
            ],
        ];
    }

    /**
     * @param  Collection<int, AcademicYear>  $years  urut terbaru dulu
     * @return list<YearRow>
     */
    private function yearRows(Collection $years): array
    {
        if ($years->isEmpty()) {
            return [];
        }

        $terms = Term::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('academic_year_id', $years->pluck('id'))
            ->orderBy('sequence')
            ->get();

        $rows = [];

        foreach ($years as $year) {
            $termRows = [];

            foreach ($terms->where('academic_year_id', $year->id) as $term) {
                $termRows[] = [
                    'sequence' => $term->sequence,
                    'name' => $term->name,
                    'start_date' => $term->start_date->toDateString(),
                    'end_date' => $term->end_date->toDateString(),
                ];
            }

            $rows[] = [
                'id' => $year->id,
                'name' => $year->name,
                'start_date' => $year->start_date->toDateString(),
                'end_date' => $year->end_date->toDateString(),
                'is_current' => $year->is_current,
                'terms' => $termRows,
            ];
        }

        return $rows;
    }

    /**
     * Aturan pemakaian kalender yang tersimpan di yayasan ini.
     *
     * @param  array<string, string>  $calendarNames  id kalender => nama
     * @return list<RuleRow>
     */
    private function rules(string $tenantId, array $calendarNames): array
    {
        $settings = ScopedSetting::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('key', CalendarSetting::KEY)
            ->get();

        if ($settings->isEmpty()) {
            return [];
        }

        $nodes = Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $settings->pluck('organization_id')->filter())
            ->get();

        $nodeNames = [];

        foreach ($nodes as $node) {
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

            $calendarId = $setting->value;

            $rows[] = [
                'id' => $setting->id,
                'target' => $target,
                'calendar' => is_string($calendarId) && isset($calendarNames[$calendarId]) ? $calendarNames[$calendarId] : 'Kalender tidak ditemukan',
                'enforced' => $setting->is_enforced,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['target'], $b['target']));

        return $rows;
    }

    /**
     * Kalender & tahun ajaran berjalan yang berlaku di tiap lembaga aktif.
     *
     * @return list<UsageRow>
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
            $usage = $this->resolver->usageFor($tenantId, $organization->id);
            $calendar = $usage['calendar'];

            $source = 'Bawaan';

            if ($usage['setting']->source === SettingPrecedence::SOURCE_ENFORCED) {
                $source = 'Dikunci';
            } elseif ($usage['setting']->source === SettingPrecedence::SOURCE_NEAREST) {
                $source = 'Diatur';
            }

            $currentYear = $calendar === null ? null : $this->resolver->currentYearOf($calendar);

            $rows[] = [
                'id' => $organization->id,
                'name' => $organization->name,
                'calendar' => $calendar?->name,
                'current_year' => $currentYear?->name,
                'source' => $source,
            ];
        }

        return $rows;
    }

    /**
     * Unit dan lembaga aktif sebagai pilihan target pemakaian kalender.
     *
     * @return list<OptionRow>
     */
    private function organizationOptions(string $tenantId): array
    {
        $organizations = Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('type', [OrganizationType::Lembaga->value, OrganizationType::Unit->value])
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get();

        $options = [];

        foreach ($organizations as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }
}
