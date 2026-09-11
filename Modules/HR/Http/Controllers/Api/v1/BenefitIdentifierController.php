<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Contracts\EmployeeBenefitIdentifierRepositoryInterface;
use Modules\HR\Http\Requests\StoreBenefitIdentifierRequest;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HR-006 §7.7 — Employee Benefit Identifier (nomor BPJS, dst.).
 * SENGAJA controller tipis langsung memanggil repository (bukan
 * service) — tidak ada logika bisnis lifecycle di sini, murni
 * store/list terenkripsi lewat `EloquentEmployeeBenefitIdentifierRepository`
 * yang sudah dibangun Langkah 5.6.
 *
 * Data sensitif: `value` mentah TIDAK PERNAH di-log, dan `store()`
 * TIDAK PERNAH mengembalikan value (dekripsi atau ciphertext) dalam
 * response — hanya metadata (id, identifier_type, status).
 */
final class BenefitIdentifierController extends Controller
{
    public function __construct(
        private readonly EmployeeBenefitIdentifierRepositoryInterface $repository,
    ) {}

    /**
     * List identifier AKTIF milik satu participation, dengan value
     * SUDAH didekripsi — endpoint ini SENGAJA digerbang permission
     * terpisah (`hr.benefit.identifiers.view`) dari `store`, karena
     * membaca nilai mentah adalah operasi lebih sensitif daripada
     * sekadar menulisnya.
     */
    public function index(Request $request, string $participationId): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $participationExists = DB::table('employee_benefit_participations')
            ->where('id', $participationId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $participationExists) {
            return $this->notFoundResponse($participationId);
        }

        $identifiers = $this->repository->listForParticipationWithDecryptedValue(
            tenantId: $tenantId,
            participationId: $participationId,
        );

        return response()->json([
            'status' => 'success',
            'data' => $identifiers,
        ]);
    }

    public function store(
        StoreBenefitIdentifierRequest $request,
        string $participationId,
    ): JsonResponse {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $benefitProgramId = DB::table('employee_benefit_participations')
            ->where('id', $participationId)
            ->where('tenant_id', $tenantId)
            ->value('benefit_program_id');

        if ($benefitProgramId === null) {
            return $this->notFoundResponse($participationId);
        }

        /**
         * @var array{
         *     identifier_type: string,
         *     value: string,
         *     issuer?: string|null,
         *     issued_at?: string|null,
         *     expires_at?: string|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $stored = $this->repository->store(
                tenantId: $tenantId,
                participationId: $participationId,
                benefitProgramId: $benefitProgramId,
                identifierType: $payload['identifier_type'],
                rawValue: $payload['value'],
                issuer: $payload['issuer'] ?? null,
                issuedAt: $payload['issued_at'] ?? null,
                expiresAt: $payload['expires_at'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'EmployeeBenefitIdentifier creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'employee_benefit_participation_id' => $participationId,
                    // identifier_type dicatat (bukan sensitif), 'value'
                    // TIDAK PERNAH masuk log dalam bentuk apa pun.
                    'identifier_type' => $payload['identifier_type'],
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'BENEFIT_IDENTIFIER_CREATION_FAILED',
                message: 'Failed to persist EmployeeBenefitIdentifier record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $stored,
        ], 201);
    }

    private function conflictResponse(RuntimeException $exception): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'BENEFIT_IDENTIFIER_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $participationId): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'BENEFIT_PARTICIPATION_NOT_FOUND',
            message: sprintf(
                'EmployeeBenefitParticipation [%s] was not found in the current tenant.',
                $participationId,
            ),
            status: Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function isCanonicalUuid(mixed $value): bool
    {
        return is_string($value)
            && Str::isUuid(trim($value));
    }

    private function authenticationContextDeniedResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_CONTEXT_DENIED',
            message: 'Authentication context missing or invalid.',
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
