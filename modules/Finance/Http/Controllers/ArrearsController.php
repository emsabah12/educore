<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Report\Arrears;
use Modules\Finance\Application\Report\ArrearsExport;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tunggakan siswa (PRD-003 §6, FN-4a §17): ringkasan per lembaga dalam cakupan, lalu per kelas & per siswa
 * untuk lembaga terpilih, unduh .xlsx. Butuh `finance.billing.view`; lembaga di luar cakupan → 404.
 */
final class ArrearsController
{
    use WorksPerOrganization;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, Arrears $arrears): Response
    {
        $organizations = $this->organizations();

        if ($organizations === []) {
            abort(403);
        }

        $today = BusinessClock::todayString();
        $organizationIds = array_column($organizations, 'value');
        $organizationId = $this->pick($organizationIds, $request->query('lembaga'));
        $names = array_column($organizations, 'label', 'value');

        $summary = [];

        foreach ($arrears->byOrganization($this->tenantId(), $organizationIds, $today) as $row) {
            $summary[] = $row + ['name' => $names[$row['organization_id']] ?? ''];
        }

        $data = null;
        $classOptions = [];
        $class = null;

        if ($organizationId !== null) {
            // Pilihan kelas dari seluruh tunggakan lembaga; data yang ditampilkan mengikuti saringan.
            $data = $arrears->forOrganization($this->tenantId(), $organizationId, $today);
            $classOptions = Arrears::classOptions($data['classes']);
            $class = $this->pick(array_column($classOptions, 'value'), $request->query('kelas'), '') ?: null;

            if ($class !== null) {
                $data = $arrears->forOrganization($this->tenantId(), $organizationId, $today, $class);
            }
        }

        $students = $data['students'] ?? [];
        $lastPage = max(1, (int) ceil(count($students) / Arrears::PER_PAGE));
        $page = min(max(1, $request->integer('halaman', 1)), $lastPage);

        return Inertia::render('finance/arrears', [
            'organizations' => $organizations,
            'organizationId' => $organizationId,
            'today' => $today,
            'summary' => $summary,
            'classOptions' => $classOptions,
            'classFilter' => $class,
            'arrears' => $data === null ? null : [
                'total' => $data['total'],
                'student_count' => count($students),
                'invoice_count' => count($data['invoices']),
                'classes' => $data['classes'],
                'students' => array_slice($students, ($page - 1) * Arrears::PER_PAGE, Arrears::PER_PAGE),
                'page' => $page,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function export(Request $request, ArrearsExport $export, Arrears $arrears): BinaryFileResponse
    {
        $organizationId = $request->query('lembaga');

        if (! is_string($organizationId) || ! in_array($organizationId, array_column($this->organizations(), 'value'), true)) {
            abort(404);
        }

        $this->authorizeAt($organizationId, FinanceAccess::BILLING_VIEW);
        $organization = Organization::query()->whereKey($organizationId)->firstOrFail();
        $today = BusinessClock::todayString();

        $requested = $request->query('kelas');
        $class = null;

        if (is_string($requested) && $requested !== '') {
            $options = Arrears::classOptions($arrears->forOrganization($this->tenantId(), $organizationId, $today)['classes']);
            $class = $this->pick(array_column($options, 'value'), $requested, '') ?: null;
        }

        $path = tempnam(sys_get_temp_dir(), 'educore-tunggakan-');

        if ($path === false) {
            abort(500, 'File tunggakan tidak bisa dibuat.');
        }

        $export->write($this->tenantId(), $organizationId, $organization->name, $today, $class, $path);

        return response()
            ->download($path, ArrearsExport::fileName($organization->code, $today), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}
