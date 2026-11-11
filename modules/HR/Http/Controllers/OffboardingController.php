<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Offboarding\ManageOffboarding;
use Modules\HR\Application\Offboarding\OffboardingChecklist;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;

/**
 * Offboarding pegawai keluar (PRD-001 §18.2, HR-7b): halaman Pegawai keluar, buat checklist untuk pegawai yang
 * berhenti sebelum HR-7b, ubah status item. Hanya Admin SDM / Admin Yayasan di tingkat Yayasan
 * (`hr.employees.manage`, OD-HR-02); selain itu 403.
 */
final class OffboardingController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, OffboardingChecklist $checklist): Response
    {
        $this->authorize();
        $filter = $request->string('status')->value() === OffboardingChecklist::FILTER_ALL ? OffboardingChecklist::FILTER_ALL : OffboardingChecklist::FILTER_OPEN;

        return Inertia::render('hr/offboarding/index', [
            'rows' => $checklist->list($this->tenantContext->require()->tenantId, $filter),
            'filter' => $filter,
        ]);
    }

    public function start(string $employee, ManageOffboarding $offboarding): RedirectResponse
    {
        $this->authorize();
        $context = $this->tenantContext->require();
        $this->attempt(fn () => $offboarding->startForEnded($context->tenantId, $employee, $context->membershipId));

        return $this->done('Checklist offboarding dibuat.');
    }

    public function updateTask(Request $request, string $employee, string $task, ManageOffboarding $offboarding): RedirectResponse
    {
        $this->authorize();
        $context = $this->tenantContext->require();
        $this->attempt(fn () => $offboarding->updateTask(
            $context->tenantId,
            $employee,
            $task,
            $request->string('status')->value(),
            $request->filled('note') ? $request->string('note')->value() : null,
            $context->membershipId,
        ));

        return $this->done('Checklist disimpan.');
    }

    private function authorize(): void
    {
        $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->authorize();
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (EmployeeException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
