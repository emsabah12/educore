<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Exceptions\RecruitmentLifecycleException;
use Modules\HR\Http\Requests\StoreHireConversionRequest;
use Modules\HR\Services\HireConversionService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HR-003 §12 — Hiring Conversion Transaction (resolves Fase E, RM-HR-03).
 */
final class HireConversionController extends Controller
{
    public function __construct(
        private readonly HireConversionService $hireConversionService,
    ) {}

    public function store(
        StoreHireConversionRequest $request,
        string $applicationId,
    ): JsonResponse {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );
        $membershipId = $request->attributes->get(
            'authenticated_membership_id',
        );

        if (! $this->isCanonicalUuid($tenantId) || ! $this->isCanonicalUuid($membershipId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     employment_type_id: string,
         *     start_date: string,
         *     confirm_create_new_person: bool|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $conversion = $this->hireConversionService->convert(
                tenantId: $tenantId,
                applicationId: $applicationId,
                employmentInput: [
                    'employment_type_id' => $payload['employment_type_id'],
                    'start_date' => $payload['start_date'],
                ],
                actorMembershipId: $membershipId,
                confirmCreateNewPerson: $payload['confirm_create_new_person'] ?? false,
            );
        } catch (ModelNotFoundException) {
            return ApiErrorResponse::make(
                code: 'RECRUITMENT_APPLICATION_NOT_FOUND',
                message: sprintf(
                    'Application [%s] was not found in the current tenant.',
                    $applicationId,
                ),
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (RecruitmentLifecycleException $exception) {
            return ApiErrorResponse::make(
                code: 'HIRE_CONVERSION_CONFLICT',
                message: $exception->getMessage(),
                status: Response::HTTP_CONFLICT,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Hire conversion failed.',
                [
                    'tenant_id' => $tenantId,
                    'application_id' => $applicationId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'HIRE_CONVERSION_FAILED',
                message: 'Failed to complete hiring conversion.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        // Sengaja selalu 200 OK (bukan 201) — endpoint ini idempoten:
        // panggilan ulang terhadap Application yang sama mengembalikan
        // hasil konversi yang SAMA, bukan selalu "resource baru".
        return response()->json([
            'status' => 'success',
            'message' => 'Hiring conversion succeeded. Employee provisioned with PLANNED Employment.',
            'data' => $conversion,
        ]);
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
