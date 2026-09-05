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
use Modules\HR\Contracts\RecruitmentCandidateIdentifierRepositoryInterface;
use Modules\HR\Http\Requests\StoreRecruitmentCandidateRequest;
use Modules\HR\Models\RecruitmentCandidate;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RecruitmentCandidateController extends Controller
{
    public function __construct(
        private readonly RecruitmentCandidateIdentifierRepositoryInterface $identifierRepository,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $perPage = max(
            1,
            min(
                (int) $request->query('per_page', '15'),
                100,
            ),
        );

        $candidates = RecruitmentCandidate::query()
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $candidates->items(),
            'meta' => [
                'current_page' => $candidates->currentPage(),
                'last_page' => $candidates->lastPage(),
                'per_page' => $candidates->perPage(),
                'total' => $candidates->total(),
            ],
        ]);
    }

    public function store(StoreRecruitmentCandidateRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     display_name: string,
         *     birth_date: string|null,
         *     primary_email: string|null,
         *     primary_phone: string|null,
         *     source: string|null,
         *     identifiers: array<int, array{type:string,issuing_country_code:string,value:string}>|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $candidate = DB::transaction(function () use ($tenantId, $payload): RecruitmentCandidate {
                $candidate = RecruitmentCandidate::create([
                    'display_name' => $payload['display_name'],
                    'birth_date' => $payload['birth_date'] ?? null,
                    'primary_email' => $payload['primary_email'] ?? null,
                    'normalized_email' => isset($payload['primary_email'])
                        ? Str::lower(trim($payload['primary_email']))
                        : null,
                    'primary_phone' => $payload['primary_phone'] ?? null,
                    'normalized_phone' => $payload['primary_phone'] ?? null,
                    'source' => $payload['source'] ?? null,
                ]);

                foreach ($payload['identifiers'] ?? [] as $identifier) {
                    $this->identifierRepository->store(
                        tenantId: $tenantId,
                        candidateId: $candidate->id,
                        type: $identifier['type'],
                        issuingCountryCode: $identifier['issuing_country_code'],
                        rawValue: $identifier['value'],
                    );
                }

                return $candidate;
            });
        } catch (RuntimeException $exception) {
            // INV-REC-003 — identifier kuat sudah dimiliki Candidate lain.
            return ApiErrorResponse::make(
                code: 'RECRUITMENT_CANDIDATE_IDENTIFIER_CONFLICT',
                message: $exception->getMessage(),
                status: Response::HTTP_CONFLICT,
            );
        } catch (Throwable $exception) {
            Log::error(
                'RecruitmentCandidate creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'RECRUITMENT_CANDIDATE_CREATION_FAILED',
                message: 'Failed to persist RecruitmentCandidate record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Candidate created.',
            'data' => $candidate,
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
