<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Attendance\AttendanceModeOverview;
use Modules\Academic\Application\Attendance\AttendanceModeSetting;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Calendar\AcademicYearData;
use Modules\Academic\Application\Calendar\AssignCalendar;
use Modules\Academic\Application\Calendar\CalendarOverview;
use Modules\Academic\Application\Calendar\ManageCalendars;
use Modules\Academic\Application\Calendar\ManageHolidays;
use Modules\Academic\Application\Calendar\SaveAcademicYear;
use Modules\Academic\Application\Calendar\SetCurrentYear;
use Modules\Academic\Application\Grading\GradingOverview;
use Modules\Academic\Application\Grading\GradingRules;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Exceptions\CalendarException;
use Modules\Academic\Domain\Calendar\Holiday;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Settings\Exceptions\SettingException;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;

/**
 * Halaman Kalender akademik (PRD-002 §4.4): kalender, tahun ajaran & semester, hari libur (§18 P4),
 * pemakaian kalender, mode presensi (§17) dan aturan penilaian (§19 AC-5a).
 *
 * Butuh `academic.calendar.manage` di tingkat Yayasan (role tenant-wide, lembaga kerja
 * Seluruh Yayasan), karena kalender berlaku untuk seluruh yayasan (PRD-002 §5.1).
 */
final class CalendarController
{
    private const DATE_RULE = 'date_format:Y-m-d';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, CalendarOverview $overview, AttendanceModeOverview $attendanceModes, GradingOverview $grading): Response
    {
        $this->authorize();

        $selected = $request->string('kalender')->value();

        return Inertia::render('academic/calendar', [
            ...$overview->build($this->tenantId(), Str::isUuid($selected) ? $selected : null),
            // Mode presensi per lembaga (AC-4c).
            'attendanceModes' => $attendanceModes->build($this->tenantId()),
            // Aturan penilaian per lembaga (AC-5a).
            'grading' => $grading->build($this->tenantId()),
            'today' => BusinessClock::todayString(),
            'limits' => [
                'maxTerms' => SaveAcademicYear::MAX_TERMS,
                'maxMonths' => SaveAcademicYear::MAX_MONTHS,
            ],
        ]);
    }

    public function storeCalendar(Request $request, ManageCalendars $calendars): RedirectResponse
    {
        $this->authorize();

        $request->validate([
            'name' => ['required', 'string', 'max:'.ManageCalendars::NAME_MAX_LENGTH],
        ], [], ['name' => 'nama kalender']);

        $calendar = $this->attempt(fn () => $calendars->create($this->tenantId(), $request->string('name')->value()));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$calendar->name} berhasil ditambahkan."]);

        return redirect()->route('academic.calendar.index', ['kalender' => $calendar->id]);
    }

    public function renameCalendar(Request $request, string $calendar, ManageCalendars $calendars): RedirectResponse
    {
        $this->authorize();
        $target = $this->findCalendar($calendar);

        $request->validate([
            'name' => ['required', 'string', 'max:'.ManageCalendars::NAME_MAX_LENGTH],
        ], [], ['name' => 'nama kalender']);

        $renamed = $this->attempt(fn () => $calendars->rename($this->tenantId(), $target->id, $request->string('name')->value()));

        return $this->done("Nama kalender diubah menjadi {$renamed->name}.");
    }

    public function storeYear(Request $request, string $calendar, SaveAcademicYear $saveAcademicYear): RedirectResponse
    {
        $this->authorize();
        $target = $this->findCalendar($calendar);
        $data = $this->yearData($request);

        $year = $this->attempt(fn () => $saveAcademicYear->create($this->tenantId(), $target->id, $data));

        $message = $year->is_current
            ? "Tahun ajaran {$year->name} ditambahkan dan menjadi tahun berjalan."
            : "Tahun ajaran {$year->name} ditambahkan.";

        return $this->done($message);
    }

    public function updateYear(Request $request, string $year, SaveAcademicYear $saveAcademicYear): RedirectResponse
    {
        $this->authorize();
        $target = $this->findYear($year);
        $data = $this->yearData($request);

        $updated = $this->attempt(fn () => $saveAcademicYear->update($this->tenantId(), $target->id, $data));

        return $this->done("Tahun ajaran {$updated->name} disimpan.");
    }

    public function setCurrent(string $year, SetCurrentYear $setCurrentYear): RedirectResponse
    {
        $this->authorize();
        $target = $this->findYear($year);

        $current = $this->attempt(fn () => $setCurrentYear->handle($this->tenantId(), $target->id));

        return $this->done("{$current->name} sekarang menjadi tahun ajaran berjalan.");
    }

    public function assign(Request $request, AssignCalendar $assignCalendar): RedirectResponse
    {
        $this->authorize();

        $validated = $request->validate([
            'target' => ['required', Rule::in(['jenjang', 'lembaga'])],
            'jenjang' => ['nullable', 'required_if:target,jenjang', Rule::enum(Jenjang::class)],
            'organization_id' => ['nullable', 'required_if:target,lembaga', 'uuid'],
            'calendar_id' => ['required', 'uuid'],
            'enforced' => ['nullable', 'boolean'],
        ], [
            'jenjang.required_if' => 'Pilih jenjang.',
            'organization_id.required_if' => 'Pilih lembaga atau unit.',
        ], [
            'jenjang' => 'jenjang',
            'organization_id' => 'lembaga',
            'calendar_id' => 'kalender',
        ]);

        $byJenjang = $validated['target'] === 'jenjang';
        $jenjang = $byJenjang ? Jenjang::from((string) $validated['jenjang']) : null;
        $organizationId = $byJenjang ? null : (string) $validated['organization_id'];

        try {
            $assignCalendar->handle($organizationId, $jenjang, (string) $validated['calendar_id'], $request->boolean('enforced'));
        } catch (CalendarException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        } catch (SettingException $exception) {
            throw ValidationException::withMessages(['calendar_id' => $exception->getMessage()]);
        }

        return $this->done('Pemakaian kalender disimpan.');
    }

    /**
     * Mode presensi (PRD-002 §17 AC-4c) untuk satu jenjang atau lembaga/unit; hanya Admin Yayasan.
     */
    public function assignAttendanceMode(Request $request, AttendanceModeOverview $attendanceModes): RedirectResponse
    {
        $this->authorize();

        $validated = $request->validate([
            'target' => ['required', Rule::in(['jenjang', 'lembaga'])],
            'jenjang' => ['nullable', 'required_if:target,jenjang', Rule::enum(Jenjang::class)],
            'organization_id' => ['nullable', 'required_if:target,lembaga', 'uuid'],
            'mode' => ['required', Rule::in([AttendanceModeSetting::DAILY, AttendanceModeSetting::LESSON])],
            'enforced' => ['nullable', 'boolean'],
        ], [
            'jenjang.required_if' => 'Pilih jenjang.',
            'organization_id.required_if' => 'Pilih lembaga atau unit.',
            'mode.required' => 'Pilih mode presensi.',
        ], [
            'jenjang' => 'jenjang',
            'organization_id' => 'lembaga',
            'mode' => 'mode presensi',
        ]);

        $byJenjang = $validated['target'] === 'jenjang';

        try {
            $attendanceModes->assign(
                $byJenjang ? null : (string) $validated['organization_id'],
                $byJenjang ? Jenjang::from((string) $validated['jenjang']) : null,
                (string) $validated['mode'],
                $request->boolean('enforced'),
            );
        } catch (SettingException $exception) {
            throw ValidationException::withMessages(['mode' => $exception->getMessage()]);
        }

        return $this->done('Mode presensi disimpan.');
    }

    /**
     * Aturan penilaian (PRD-002 §19 AC-5a) untuk satu jenjang atau lembaga/unit; hanya Admin Yayasan.
     */
    public function assignGrading(Request $request, GradingOverview $grading): RedirectResponse
    {
        $this->authorize();

        // Form baru mengirim `aspects` (AC-8a) & `predicates` (AC-8b); `components` dan batas A/B
        // (format AC-5a) tetap diterima.
        $byAspects = $request->has('aspects');
        $byScale = $request->has('predicates');

        $validated = $request->validate([
            'target' => ['required', Rule::in(['jenjang', 'lembaga'])],
            'jenjang' => ['nullable', 'required_if:target,jenjang', Rule::enum(Jenjang::class)],
            'organization_id' => ['nullable', 'required_if:target,lembaga', 'uuid'],
            'kkm' => ['required', 'integer', 'between:0,100'],
            ...($byScale ? [
                'predicates' => ['required', 'array', 'min:'.GradingRules::MIN_LEVELS, 'max:'.GradingRules::MAX_LEVELS],
                'predicates.*' => ['array'],
                'predicates.*.letter' => ['required', 'string', 'max:2'],
                'predicates.*.label' => ['nullable', 'string', 'max:'.GradingRules::LABEL_MAX_LENGTH],
                'predicates.*.min' => ['nullable', 'integer', 'between:0,100'],
            ] : [
                'grade_a' => ['required', 'integer', 'between:0,100'],
                'grade_b' => ['required', 'integer', 'between:0,100'],
            ]),
            ...($byAspects ? [
                'aspects' => ['required', 'array', 'min:1', 'max:'.GradingRules::MAX_ASPECTS],
                'aspects.*' => ['array'],
                'aspects.*.code' => ['required', 'string', 'max:10'],
                'aspects.*.name' => ['required', 'string', 'max:'.GradingRules::NAME_MAX_LENGTH],
                'aspects.*.components' => ['required', 'array', 'min:1', 'max:'.GradingRules::MAX_COMPONENTS],
                'aspects.*.components.*' => ['array'],
                'aspects.*.components.*.code' => ['required', 'string', 'max:10'],
                'aspects.*.components.*.name' => ['required', 'string', 'max:'.GradingRules::NAME_MAX_LENGTH],
                'aspects.*.components.*.weight' => ['required', 'integer', 'between:1,100'],
            ] : [
                'components' => ['required', 'array', 'min:1', 'max:'.GradingRules::MAX_COMPONENTS],
                'components.*' => ['array'],
                'components.*.code' => ['required', 'string', 'max:10'],
                'components.*.name' => ['required', 'string', 'max:'.GradingRules::NAME_MAX_LENGTH],
                'components.*.weight' => ['required', 'integer', 'between:1,100'],
            ]),
            'enforced' => ['nullable', 'boolean'],
        ], [
            'jenjang.required_if' => 'Pilih jenjang.',
            'organization_id.required_if' => 'Pilih lembaga atau unit.',
            'aspects.required' => 'Isi minimal satu aspek penilaian.',
            'aspects.max' => 'Paling banyak '.GradingRules::MAX_ASPECTS.' aspek penilaian.',
            'aspects.*.components.required' => 'Isi minimal satu komponen nilai di tiap aspek.',
            'aspects.*.components.max' => 'Paling banyak '.GradingRules::MAX_COMPONENTS.' komponen nilai per aspek.',
            'components.required' => 'Isi minimal satu komponen nilai.',
            'components.max' => 'Paling banyak '.GradingRules::MAX_COMPONENTS.' komponen nilai.',
            'predicates.min' => 'Isi minimal '.GradingRules::MIN_LEVELS.' tingkat predikat.',
            'predicates.max' => 'Paling banyak '.GradingRules::MAX_LEVELS.' tingkat predikat.',
        ], [
            'jenjang' => 'jenjang',
            'organization_id' => 'lembaga',
            'kkm' => 'KKM',
            'grade_a' => 'batas predikat A',
            'grade_b' => 'batas predikat B',
            'predicates.*.letter' => 'huruf predikat',
            'predicates.*.label' => 'sebutan predikat',
            'predicates.*.min' => 'nilai minimal predikat',
            'aspects.*.code' => 'kode aspek',
            'aspects.*.name' => 'nama aspek',
            'aspects.*.components.*.code' => 'kode komponen',
            'aspects.*.components.*.name' => 'nama komponen',
            'aspects.*.components.*.weight' => 'bobot',
            'components.*.code' => 'kode komponen',
            'components.*.name' => 'nama komponen',
            'components.*.weight' => 'bobot',
        ]);

        $rawAspects = $byAspects
            ? array_values((array) $validated['aspects'])
            : [['code' => GradingRules::DEFAULT_ASPECT_CODE, 'name' => GradingRules::DEFAULT_ASPECT_NAME, 'components' => $validated['components']]];
        $aspects = [];

        foreach ($rawAspects as $aspect) {
            $aspect = (array) $aspect;
            $components = [];

            foreach (array_values((array) $aspect['components']) as $component) {
                $component = (array) $component;
                $components[] = [
                    'code' => mb_strtoupper(trim((string) $component['code'])),
                    'name' => self::cleanText($component['name']),
                    'weight' => (int) $component['weight'],
                ];
            }

            $aspects[] = ['code' => mb_strtoupper(trim((string) $aspect['code'])), 'name' => self::cleanText($aspect['name']), 'components' => $components];
        }

        $value = ['kkm' => (int) $validated['kkm'], 'aspects' => $aspects];

        if ($byScale) {
            $levels = array_values((array) $validated['predicates']);
            $value['predicates'] = [];

            foreach ($levels as $index => $level) {
                $level = (array) $level;
                $label = self::cleanText($level['label'] ?? '');
                $value['predicates'][] = [
                    'letter' => mb_strtoupper(trim((string) $level['letter'])),
                    'label' => $label === '' ? null : $label,
                    // Tingkat terakhir selalu mulai dari 0.
                    'min' => $index === count($levels) - 1 ? 0 : (int) ($level['min'] ?? -1),
                ];
            }

            $scaleProblem = GradingRules::scaleProblem($value['predicates']);

            if ($scaleProblem !== null) {
                throw ValidationException::withMessages(['predicates' => $scaleProblem]);
            }
        } else {
            $value['grade_a'] = (int) $validated['grade_a'];
            $value['grade_b'] = (int) $validated['grade_b'];
        }

        $problem = GradingRules::problem($value);

        if ($problem !== null) {
            throw ValidationException::withMessages([$byAspects ? 'aspects' : 'components' => $problem]);
        }

        $byJenjang = $validated['target'] === 'jenjang';

        try {
            $grading->assign(
                $byJenjang ? null : (string) $validated['organization_id'],
                $byJenjang ? Jenjang::from((string) $validated['jenjang']) : null,
                GradingRules::fromValue($value),
                $request->boolean('enforced'),
            );
        } catch (SettingException $exception) {
            throw ValidationException::withMessages(['form' => $exception->getMessage()]);
        }

        return $this->done('Aturan penilaian disimpan.');
    }

    /** Spasi ganda dirapikan: "  Ujian   tulis " → "Ujian tulis". */
    private static function cleanText(mixed $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim((string) $value));
    }

    /**
     * Hari libur mingguan kalender (PRD-002 §18 P4). Tidak ada yang dicentang = tanpa libur mingguan.
     */
    public function weeklyOffDays(Request $request, string $calendar, ManageHolidays $holidays): RedirectResponse
    {
        $this->authorize();
        $target = $this->findCalendar($calendar);

        $request->validate([
            'weekly_off_days' => ['nullable', 'array', 'max:7'],
            'weekly_off_days.*' => ['integer', 'between:1,7'],
        ], ['weekly_off_days.*.integer' => 'Pilih hari dari daftar.', 'weekly_off_days.*.between' => 'Pilih hari dari daftar.'], ['weekly_off_days' => 'hari libur mingguan']);

        $days = array_values(array_map('intval', (array) $request->input('weekly_off_days', [])));

        $changed = $this->attempt(fn (): bool => $holidays->setWeeklyOffDays($this->tenantId(), $target->id, $days));

        return $this->done($changed ? "Hari libur mingguan {$target->name} disimpan." : 'Tidak ada data yang berubah.');
    }

    public function storeHoliday(Request $request, string $year, ManageHolidays $holidays): RedirectResponse
    {
        $this->authorize();
        $target = $this->findYear($year);
        [$name, $start, $end] = $this->holidayInput($request);

        $holiday = $this->attempt(fn () => $holidays->add($this->tenantId(), $target->id, $name, $start, $end));

        return $this->done("Hari libur {$holiday->name} ditambahkan ke tahun ajaran {$target->name}.");
    }

    public function updateHoliday(Request $request, string $holiday, ManageHolidays $holidays): RedirectResponse
    {
        $this->authorize();
        $target = $this->findHoliday($holiday);
        [$name, $start, $end] = $this->holidayInput($request);

        $changed = $this->attempt(fn (): bool => $holidays->update($this->tenantId(), $target->id, $name, $start, $end));

        return $this->done($changed ? 'Hari libur disimpan.' : 'Tidak ada data yang berubah.');
    }

    public function destroyHoliday(string $holiday, ManageHolidays $holidays): RedirectResponse
    {
        $this->authorize();
        $target = $this->findHoliday($holiday);

        $name = $this->attempt(fn (): string => $holidays->remove($this->tenantId(), $target->id));

        return $this->done("Hari libur {$name} dihapus.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Kalender hanya dikelola di tingkat Yayasan: 403 bila tidak punya izinnya.
     */
    private function authorize(): void
    {
        $this->authorization->inspectTarget(AcademicAccess::CALENDAR_MANAGE, null, null)->authorize();
    }

    /**
     * Cari di yayasan aktif (filter yayasan otomatis). Tidak ada / milik yayasan lain → 404.
     */
    private function findCalendar(string $id): AcademicCalendar
    {
        $calendar = Str::isUuid($id) ? AcademicCalendar::query()->find($id) : null;

        if (! $calendar instanceof AcademicCalendar) {
            abort(404);
        }

        return $calendar;
    }

    private function findYear(string $id): AcademicYear
    {
        $year = Str::isUuid($id) ? AcademicYear::query()->find($id) : null;

        if (! $year instanceof AcademicYear) {
            abort(404);
        }

        return $year;
    }

    private function findHoliday(string $id): Holiday
    {
        $holiday = Str::isUuid($id) ? Holiday::query()->find($id) : null;

        if (! $holiday instanceof Holiday) {
            abort(404);
        }

        return $holiday;
    }

    /**
     * @return array{0: string, 1: string, 2: string} nama, tanggal mulai, tanggal selesai
     */
    private function holidayInput(Request $request): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:'.ManageHolidays::NAME_MAX_LENGTH],
            'start_date' => ['required', self::DATE_RULE],
            'end_date' => ['required', self::DATE_RULE],
        ], [], ['name' => 'nama hari libur', 'start_date' => 'tanggal mulai', 'end_date' => 'tanggal selesai']);

        return [
            $request->string('name')->value(),
            $request->string('start_date')->value(),
            $request->string('end_date')->value(),
        ];
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    private function yearData(Request $request): AcademicYearData
    {
        $request->validate([
            'name' => ['required', 'string', 'max:9'],
            'start_date' => ['required', self::DATE_RULE],
            'end_date' => ['required', self::DATE_RULE],
            'terms' => ['required', 'array', 'min:1', 'max:'.SaveAcademicYear::MAX_TERMS],
            'terms.*' => ['array'],
            'terms.*.name' => ['required', 'string', 'max:'.SaveAcademicYear::TERM_NAME_MAX_LENGTH],
            'terms.*.start_date' => ['required', self::DATE_RULE],
            'terms.*.end_date' => ['required', self::DATE_RULE],
        ], [
            'terms.required' => 'Isi minimal satu semester.',
            'terms.max' => 'Paling banyak '.SaveAcademicYear::MAX_TERMS.' semester.',
        ], [
            'name' => 'nama tahun ajaran',
            'start_date' => 'tanggal mulai',
            'end_date' => 'tanggal selesai',
            'terms.*.name' => 'nama semester',
            'terms.*.start_date' => 'tanggal mulai semester',
            'terms.*.end_date' => 'tanggal selesai semester',
        ]);

        $terms = [];

        foreach (array_values((array) $request->input('terms', [])) as $term) {
            $term = (array) $term;

            $terms[] = [
                'name' => (string) ($term['name'] ?? ''),
                'start_date' => (string) ($term['start_date'] ?? ''),
                'end_date' => (string) ($term['end_date'] ?? ''),
            ];
        }

        return new AcademicYearData(
            name: $request->string('name')->value(),
            startDate: $request->string('start_date')->value(),
            endDate: $request->string('end_date')->value(),
            terms: $terms,
        );
    }

    /**
     * Jalankan service; pelanggaran aturan kalender ditampilkan di isian form terkait.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (CalendarException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
