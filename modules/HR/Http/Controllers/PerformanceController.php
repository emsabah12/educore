<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Performance\AssessPerformance;
use Modules\HR\Application\Performance\ManagePerformancePeriods;
use Modules\HR\Application\Performance\PerformanceComponents;
use Modules\HR\Application\Performance\PerformanceRecap;
use Modules\HR\Application\Performance\PerformanceRecapExport;
use Modules\HR\Application\Performance\PerformanceSheet;
use Modules\HR\Domain\Performance\Exceptions\PerformanceException;
use Modules\HR\Domain\Performance\PerformanceGroup;
use Modules\HR\Domain\Performance\Predicate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Penilaian kinerja ringkas (PRD-001 §19.2, HR-8b; OD-HR-17).
 *
 * - Lembar penilaian & menilai: `hr.performance.assess` di lembaga penempatan utama pegawai (Kepala Lembaga →
 *   lembaganya; Admin SDM / Admin Yayasan → semua). Tanpa izin 403; pegawai di luar cakupan 404.
 * - Periode & komponen, kunci/buka kunci, rekap & unduhan: `hr.performance.manage` tingkat Yayasan (HR-8b-2).
 */
final class PerformanceController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, PerformanceSheet $sheet, PerformanceComponents $components, PerformanceRecap $recap): Response
    {
        Gate::authorize(HrAccess::PERFORMANCE_ASSESS);

        $tenantId = $this->tenantContext->require()->tenantId;
        $periods = $sheet->periods($tenantId);
        $organizations = $this->organizationOptions();

        // Bawaan: periode terbaru yang sudah dimulai, atau yang terbaru bila semuanya belum dimulai.
        $periodIds = array_column($periods, 'id');
        $periodId = $request->string('periode')->value();
        $periodId = in_array($periodId, $periodIds, true)
            ? $periodId
            : (array_values(array_filter($periods, fn (array $period): bool => $period['started']))[0]['id'] ?? $periodIds[0] ?? null);

        $organizationId = $request->string('lembaga')->value();
        $organizationId = in_array($organizationId, array_column($organizations, 'value'), true) ? $organizationId : ($organizations[0]['value'] ?? null);

        $period = $periodId === null ? null : $sheet->period($tenantId, $periodId);
        $rows = $period !== null && $organizationId !== null
            ? $sheet->rows($tenantId, $period, $organizationId, $this->selfEmployeeId())
            : [];

        $canManage = $this->canManage();

        return Inertia::render('hr/performance/index', [
            'periods' => $periods,
            'organizations' => $organizations,
            'filters' => ['periode' => $periodId, 'lembaga' => $organizationId],
            'rows' => $rows,
            'components' => $components->all($tenantId),
            'groups' => array_map(fn (PerformanceGroup $group): array => ['value' => $group->value, 'slug' => $group->slug(), 'label' => $group->label()], PerformanceGroup::cases()),
            'predicates' => [
                ['value' => Predicate::Excellent->value, 'label' => Predicate::Excellent->label(), 'range' => '91–100'],
                ['value' => Predicate::Good->value, 'label' => Predicate::Good->label(), 'range' => '76–90'],
                ['value' => Predicate::Fair->value, 'label' => Predicate::Fair->label(), 'range' => '61–75'],
                ['value' => Predicate::Moderate->value, 'label' => Predicate::Moderate->label(), 'range' => '51–60'],
                ['value' => Predicate::Poor->value, 'label' => Predicate::Poor->label(), 'range' => '≤ 50'],
            ],
            'canManage' => $canManage,
            'recap' => $canManage && $periodId !== null ? $recap->summary($tenantId, $periodId) : [],
            'limits' => [
                'note' => AssessPerformance::NOTE_MAX,
                'periodName' => ManagePerformancePeriods::NAME_MAX,
                'componentName' => PerformanceComponents::NAME_MAX,
                'components' => PerformanceComponents::MAX_COMPONENTS,
                'reason' => ManagePerformancePeriods::REASON_MAX,
            ],
        ]);
    }

    public function assess(Request $request, string $period, string $employee, PerformanceSheet $sheet, AssessPerformance $assess): RedirectResponse
    {
        Gate::authorize(HrAccess::PERFORMANCE_ASSESS);
        $context = $this->tenantContext->require();
        $organizationId = $sheet->primaryOrganization($context->tenantId, $employee);

        if ($organizationId === null || ! $this->canAssessIn($organizationId)) {
            abort(404);
        }

        $scores = $request->input('scores', []);

        $this->attempt(fn () => $assess->assess(
            $context->tenantId,
            $period,
            $employee,
            $organizationId,
            $request->string('group')->value(),
            is_array($scores) ? $scores : [],
            $request->string('strengths')->value(),
            $request->string('suggestions')->value(),
            $context->membershipId,
        ));

        return $this->done('Penilaian kinerja disimpan.');
    }

    public function storePeriod(Request $request, ManagePerformancePeriods $periods): RedirectResponse
    {
        $this->authorizeManage();
        $context = $this->tenantContext->require();

        $id = '';
        $this->attempt(function () use ($request, $periods, $context, &$id): void {
            $id = $periods->create(
                $context->tenantId,
                $request->string('name')->value(),
                $request->string('start_date')->value(),
                $request->string('end_date')->value(),
                $context->membershipId,
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Periode penilaian dibuat.']);

        return redirect()->route('hr.performance.index', ['periode' => $id, 'tab' => 'periode']);
    }

    public function updatePeriod(Request $request, string $period, ManagePerformancePeriods $periods): RedirectResponse
    {
        $this->authorizeManage();

        $this->attempt(fn () => $periods->update(
            $this->tenantContext->require()->tenantId,
            $period,
            $request->string('name')->value(),
            $request->string('start_date')->value(),
            $request->string('end_date')->value(),
        ));

        return $this->done('Periode penilaian diubah.');
    }

    public function destroyPeriod(string $period, ManagePerformancePeriods $periods): RedirectResponse
    {
        $this->authorizeManage();

        $this->attempt(fn () => $periods->delete($this->tenantContext->require()->tenantId, $period));

        return $this->done('Periode penilaian dihapus.');
    }

    public function lockPeriod(string $period, ManagePerformancePeriods $periods): RedirectResponse
    {
        $this->authorizeManage();
        $context = $this->tenantContext->require();

        $this->attempt(fn () => $periods->lock($context->tenantId, $period, $context->membershipId));

        return $this->done('Periode dikunci. Nilai tidak bisa diubah lagi dan hasilnya terlihat pegawai di Kinerja saya.');
    }

    public function unlockPeriod(Request $request, string $period, ManagePerformancePeriods $periods): RedirectResponse
    {
        $this->authorizeManage();

        $this->attempt(fn () => $periods->unlock($this->tenantContext->require()->tenantId, $period, $request->string('reason')->value()));

        return $this->done('Kunci periode dibuka. Hasil penilaian disembunyikan dari pegawai sampai dikunci lagi.');
    }

    public function export(Request $request, PerformanceSheet $sheet, PerformanceRecap $recap, PerformanceRecapExport $export): BinaryFileResponse
    {
        $this->authorizeManage();
        $tenantId = $this->tenantContext->require()->tenantId;
        $period = $sheet->period($tenantId, $request->string('periode')->value()) ?? abort(404);
        $path = tempnam(sys_get_temp_dir(), 'educore-kinerja-');

        if ($path === false) {
            abort(500, 'File unduhan tidak bisa dibuat.');
        }

        $status = $period->locked_at === null ? 'BELUM DIKUNCI' : 'dikunci';
        $export->write(
            $recap->summary($tenantId, (string) $period->id),
            $recap->details($tenantId, (string) $period->id),
            "Rekap penilaian kinerja {$period->name} — {$status} (per ".BusinessClock::todayString().')',
            $path,
        );

        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $period->name)), '-');

        return response()
            ->download($path, 'rekap-kinerja-'.($slug === '' ? 'periode' : $slug).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    public function saveComponents(Request $request, string $group, PerformanceComponents $components): RedirectResponse
    {
        $this->authorizeManage();
        $groupCase = PerformanceGroup::fromSlug($group) ?? abort(404);
        $rows = $request->input('components', []);

        $this->attempt(fn () => $components->save($this->tenantContext->require()->tenantId, $groupCase, is_array($rows) ? $rows : []));

        return $this->done('Komponen penilaian '.mb_strtolower($groupCase->label()).' disimpan.');
    }

    private function canManage(): bool
    {
        return $this->authorization->inspectTarget(HrAccess::PERFORMANCE_MANAGE, null, null)->allowed();
    }

    private function authorizeManage(): void
    {
        $this->authorization->inspectTarget(HrAccess::PERFORMANCE_MANAGE, null, null)->authorize();
    }

    private function canAssessIn(string $organizationId): bool
    {
        if ($this->authorization->inspectTarget(HrAccess::PERFORMANCE_ASSESS, null, null)->allowed()) {
            return true;
        }

        return in_array($organizationId, $this->authorization->visibleNodeIds(HrAccess::PERFORMANCE_ASSESS), true);
    }

    /**
     * Data pegawai milik pengguna sendiri (tidak boleh menilai diri sendiri).
     */
    private function selfEmployeeId(): ?string
    {
        $context = $this->tenantContext->require();
        $id = DB::table('hr_employees')->where('tenant_id', $context->tenantId)->where('membership_id', $context->membershipId)->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Lembaga aktif yang boleh dinilai (semua bila tingkat Yayasan).
     *
     * @return list<array{value: string, label: string}>
     */
    private function organizationOptions(): array
    {
        $query = Organization::query()->where('status', OrganizationStatus::Active->value)->orderBy('code');

        if (! $this->authorization->inspectTarget(HrAccess::PERFORMANCE_ASSESS, null, null)->allowed()) {
            $query->whereIn('id', $this->authorization->visibleNodeIds(HrAccess::PERFORMANCE_ASSESS));
        }

        $options = [];

        foreach ($query->get() as $organization) {
            $options[] = ['value' => (string) $organization->id, 'label' => (string) $organization->name];
        }

        return $options;
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (PerformanceException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
