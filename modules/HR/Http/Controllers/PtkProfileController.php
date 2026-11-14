<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\EmployeeVisibility;
use Modules\HR\Application\Ptk\PtkProfiles;
use Modules\HR\Domain\Ptk\Exceptions\PtkException;

/**
 * Ubah Data PTK pegawai (PRD-001 §21.1, HR-10a): Admin SDM / Admin Yayasan (`hr.employees.manage`, tingkat Yayasan).
 * Nama ibu kandung hanya ikut diubah bila pengubah boleh melihat data pribadi pegawai ini.
 */
final class PtkProfileController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function update(Request $request, string $employee, PtkProfiles $profiles, EmployeeVisibility $visibility): RedirectResponse
    {
        $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->authorize();

        if (! $visibility->canSee($employee, HrAccess::EMPLOYEES_VIEW)) {
            abort(404);
        }

        $request->validate([
            'mother_name' => ['nullable', 'string', 'max:'.PtkProfiles::MOTHER_NAME_MAX],
            'sd_graduation_year' => ['nullable', 'string', 'max:4'],
            'ptk_function' => ['nullable', 'string', 'max:30'],
            'school_position' => ['nullable', 'string', 'max:30'],
            'civil_servant' => ['nullable', 'string', 'max:10'],
            'certified' => ['nullable', 'string', 'max:10'],
            'certification_year' => ['nullable', 'string', 'max:4'],
            'educator_certificate_number' => ['nullable', 'string', 'max:'.PtkProfiles::CERTIFICATE_NUMBER_MAX],
        ], [], [
            'mother_name' => 'nama ibu kandung',
            'sd_graduation_year' => 'tahun lulus SD',
            'ptk_function' => 'fungsi PTK',
            'school_position' => 'jabatan',
            'civil_servant' => 'status PNS',
            'certified' => 'sertifikasi guru',
            'certification_year' => 'tahun sertifikasi',
            'educator_certificate_number' => 'nomor sertifikat pendidik',
        ]);

        $context = $this->tenantContext->require();

        try {
            $profiles->update(
                $context->tenantId,
                $employee,
                $request->only(['mother_name', 'sd_graduation_year', 'ptk_function', 'school_position', 'civil_servant', 'certified', 'certification_year', 'educator_certificate_number']),
                $context->membershipId,
                withPersonal: $visibility->canSee($employee, HrAccess::EMPLOYEES_VIEW_PERSONAL),
            );
        } catch (PtkException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Data PTK disimpan.']);

        return back();
    }
}
