<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Contracts\RecruitmentCandidateIdentifierRepositoryInterface;
use Modules\HR\Http\Requests\StoreRecruitmentCandidateIdentifierRequest;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * §Melengkapi identifier kuat (mis. NIK) ke Candidate yang SUDAH ADA
 * -- controller tipis langsung memanggil repository yang SUDAH ADA
 * (`RecruitmentCandidateIdentifierRepositoryInterface`, sudah dipakai
 * `RecruitmentCandidateController::store()` dan
 * `HireConversionService`), tidak ada logika bisnis baru di sini.
 * Pola SENGAJA identik dengan `BenefitIdentifierController::store()`.
 *
 * Data sensitif: `value` mentah TIDAK PERNAH di-log, dan `store()`
 * TIDAK PERNAH mengembalikan value (dekripsi atau ciphertext) dalam
 * response -- hanya metadata (id, type, status).
 */
final class RecruitmentCandidateIdentifierController extends Controller
{
    public function __construct(
        private readonly RecruitmentCandidateIdentifierRepositoryInterface $repository,
    ) {}

    public function store(
        StoreRecruitmentCandidateIdentifierRequest $request,
        string $candidateId,
    ): JsonResponse {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $candidateExists = DB::table('recruitment_candidates')
            ->where('id', $candidateId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $candidateExists) {
            return $this->notFoundResponse($candidateId);
        }

        /**
         * @var array{
         *     type: string,
         *     issuing_country_code: string,
         *     value: string,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $stored = $this->repository->store(
                tenantId: $tenantId,
                candidateId: $candidateId,
                type: $payload['type'],
                issuingCountryCode: $payload['issuing_country_code'],
                rawValue: $payload['value'],
            );
        } catch (RuntimeException $exception) {
            return $this->conflictResponse($exception);
        } catch (Throwable $exception) {
            Log::error(
                'RecruitmentCandidateIdentifier creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'candidate_id' => $candidateId,
                    // type dicatat (bukan sensitif), 'value' TIDAK
                    // PERNAH masuk log dalam bentuk apa pun.
                    'type' => $payload['type'],
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'RECRUITMENT_CANDIDATE_IDENTIFIER_CREATION_FAILED',
                message: 'Failed to persist RecruitmentCandidateIdentifier record.',
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
            code: 'RECRUITMENT_CANDIDATE_IDENTIFIER_CONFLICT',
            message: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notFoundResponse(string $candidateId): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'RECRUITMENT_CANDIDATE_NOT_FOUND',
            message: sprintf(
                'RecruitmentCandidate [%s] was not found in the current tenant.',
                $candidateId,
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
