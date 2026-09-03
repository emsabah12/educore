<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dipakai bersama oleh controller HR yang endpoint-nya bisa diakses baik
 * lewat permission tenant-wide MAUPUN organizationally-scoped
 * (HR-013 §30 — Resource-Scope Authorization Boundary).
 *
 * Class yang memakai trait ini WAJIB mempunyai property
 * `$organizationalContext` (OrganizationalContextInterface) dan
 * `$hrWorkforceScopeService` (HrWorkforceScopeService) — biasanya lewat
 * constructor promotion.
 */
trait ChecksHrResourceScope
{
    /**
     * Mengembalikan `null` kalau request boleh lanjut, atau
     * JsonResponse 403 kalau Employee target terbukti di luar scope.
     *
     * Route tenant-wide (tanpa InjectOrganizationalContext di
     * middleware chain-nya) TIDAK punya OrganizationalContext aktif —
     * untuk kasus itu, method ini sengaja meloloskan request begitu
     * saja, karena permission tenant-wide (`tenant.permission`) sudah
     * cukup menjadi otoritas untuk jalur itu.
     */
    private function scopeDeniedResponseIfEmployeeNotVisible(
        string $tenantId,
        string $employeeId,
    ): ?JsonResponse {
        $context = $this->organizationalContext->getCurrentContext();

        if ($context === null) {
            return null;
        }

        if (
            $this->hrWorkforceScopeService->isEmployeeVisibleInCurrentContext(
                $tenantId,
                $employeeId,
            )
        ) {
            return null;
        }

        // Lihat catatan di HrResourceScopeException: 403 di sini adalah
        // keputusan sementara (HR-013 §53 poin 5 belum mengunci 403 vs
        // 404 untuk kasus ini).
        return ApiErrorResponse::make(
            code: 'EMPLOYEE_OUT_OF_ORGANIZATIONAL_SCOPE',
            message: sprintf(
                'Employee [%s] is not visible in the current organizational workspace.',
                $employeeId,
            ),
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
