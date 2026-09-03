<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Organization\Contracts\OrganizationalAuthorizationServiceInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menegakkan permission di dalam workspace organisasi/unit yang sudah
 * diverifikasi oleh {@see InjectOrganizationalContext}.
 *
 * Ini mirror langsung dari `Modules\Core\Authorization\Http\Middleware\
 * CheckTenantPermission`, tapi memakai
 * {@see OrganizationalAuthorizationServiceInterface} sebagai gantinya —
 * yang secara internal sudah menggabungkan role tenant-wide DAN role
 * yang di-scope ke Organization/OrganizationUnit saat ini (lihat
 * `OrganizationalAuthorizationService::resolveEffectiveRoles()`).
 *
 * PENTING (HR-013 §30 — Resource-Scope Authorization Boundary):
 * middleware ini HANYA menjawab "apakah actor punya permission di
 * workspace ini?" — dia TIDAK memverifikasi apakah resource target
 * (mis. Employee tertentu) benar-benar berada di workspace tersebut.
 * Itu tetap tanggung jawab domain/service layer (lihat
 * HR-013-BR-001: "Permission ≠ Resource Ownership").
 *
 * Chain penggunaan (HR-013 §28):
 * InjectTenantContext -> InjectOrganizationalContext ->
 * organizational.permission:<permission> -> Controller.
 */
final class CheckOrganizationalPermission
{
    public function __construct(
        private readonly OrganizationalAuthorizationServiceInterface $organizationalAuthorizationService,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(
        Request $request,
        Closure $next,
        string $permission,
    ): Response {
        $user = Auth::user();

        if ($user === null) {
            return $this->unauthenticatedResponse();
        }

        // Superadmin bypass, konsisten dengan CheckTenantPermission —
        // domain actor requirements tetap menjadi urusan downstream.
        if ((bool) $user->is_superadmin) {
            return $next($request);
        }

        if (! $this->organizationalAuthorizationService->hasPermission($permission)) {
            return $this->forbiddenResponse();
        }

        return $next($request);
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_REQUIRED',
            message: 'Unauthenticated. Invalid or missing identity context.',
            status: Response::HTTP_UNAUTHORIZED,
        );
    }

    private function forbiddenResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHORIZATION_DENIED',
            message: 'You are not allowed to perform this operation in the current organizational workspace.',
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
