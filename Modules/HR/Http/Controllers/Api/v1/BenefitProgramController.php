<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Http\Requests\StoreBenefitProgramRequest;
use Modules\HR\Models\BenefitProgram;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HR-006 §7.5 — Benefit Program catalog. Pola identik
 * CompensationComponentController (index + store saja untuk rilis
 * pertama ini).
 */
final class BenefitProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $programs = BenefitProgram::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $programs,
        ]);
    }

    public function store(StoreBenefitProgramRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     code: string,
         *     name: string,
         *     category: string,
         *     beneficiary_scope: string,
         *     payroll_relevance: string,
         *     description: string|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $program = BenefitProgram::create($payload);
        } catch (Throwable $exception) {
            Log::error(
                'BenefitProgram creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'BENEFIT_PROGRAM_CREATION_FAILED',
                message: 'Failed to persist BenefitProgram record.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $program,
        ], 201);
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
