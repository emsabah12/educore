<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Calendar\AcademicCalendarResolver;
use Modules\Academic\Application\Teaching\DutyRoster;
use Modules\Academic\Application\Teaching\TeachingAccess;
use Modules\Academic\Domain\Teaching\Exceptions\TeachingException;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Jadwal piket per lembaga (PRD-002 §20.2, OD-AC-13). Pemilik `academic.teaching.manage` di lembaga
 * itu; tanpa lembaga yang bisa dikelola 403, lembaga lain 404.
 */
final class DutyRosterController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TeachingAccess $access,
    ) {}

    public function index(Request $request, DutyRoster $roster, ActiveEmployees $employees, AcademicCalendarResolver $calendars): Response
    {
        $context = $this->tenantContext->require();
        $organizations = array_values(array_filter(
            $this->access->organizations($context->tenantId, null),
            fn (array $option): bool => $option['manages'],
        ));

        if ($organizations === []) {
            abort(403);
        }

        $organizationId = $this->pickOrganization($organizations, $request->string('lembaga')->value());
        $years = $roster->years($context->tenantId, $organizationId);
        $requestedYear = $request->string('tahun')->value();
        $current = $calendars->currentYearFor($context->tenantId, $organizationId)?->id;
        $yearIds = array_column($years, 'value');
        $yearId = in_array($requestedYear, $yearIds, true) ? $requestedYear : (in_array($current, $yearIds, true) ? $current : ($yearIds[0] ?? null));

        $options = [];

        foreach ($employees->inOrganization($context->tenantId, $organizationId) as $employee) {
            $options[] = ['value' => $employee['id'], 'label' => $employee['name']];
        }

        return Inertia::render('academic/teaching/duty', [
            'organizations' => array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']], $organizations),
            'organizationId' => $organizationId,
            'years' => $years,
            'yearId' => $yearId,
            'days' => $yearId === null ? [] : $roster->days($context->tenantId, $organizationId, $yearId),
            'employees' => $options,
            'max' => DutyRoster::MAX_PER_DAY,
        ]);
    }

    public function update(Request $request, DutyRoster $roster): RedirectResponse
    {
        $context = $this->tenantContext->require();

        $request->validate([
            'organization_id' => ['required', 'uuid'],
            'academic_year_id' => ['required', 'uuid'],
            'day' => ['required', 'integer', 'between:1,7'],
            'employee_ids' => ['nullable', 'array', 'max:'.DutyRoster::MAX_PER_DAY],
            'employee_ids.*' => ['uuid'],
        ], ['employee_ids.max' => 'Paling banyak '.DutyRoster::MAX_PER_DAY.' guru piket per hari.'], ['academic_year_id' => 'tahun ajaran', 'day' => 'hari']);

        $organizationId = $request->string('organization_id')->value();

        if (! $this->access->manages($organizationId)) {
            abort($this->access->managedOrganizationIds() === [] ? 403 : 404);
        }

        /** @var list<string> $employeeIds */
        $employeeIds = array_values(array_map('strval', (array) $request->input('employee_ids', [])));

        try {
            $count = $roster->set($context->tenantId, $organizationId, $request->string('academic_year_id')->value(), $request->integer('day'), $employeeIds, $context->membershipId);
        } catch (TeachingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 0 ? 'Jadwal piket hari itu dikosongkan.' : "Jadwal piket disimpan: {$count} guru."]);

        return back();
    }

    /**
     * @param  non-empty-list<array{value: string, label: string, manages: bool}>  $organizations
     */
    private function pickOrganization(array $organizations, string $requested): string
    {
        if ($requested === '') {
            return $organizations[0]['value'];
        }

        foreach ($organizations as $option) {
            if ($option['value'] === $requested) {
                return $option['value'];
            }
        }

        abort(404);
    }
}
