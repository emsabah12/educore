<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Http\Requests\StoreEmploymentTypeRequest;
use Modules\HR\Models\EmploymentType;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HR-002 §3 (OD-HR-DATA-002) — Employment Type adalah katalog
 * tenant-scoped, bukan enum global. `indexEmploymentTypes` SENGAJA
 * ungated (dipakai untuk menyusun dropdown di form pembuatan
 * Employment) — `storeEmploymentType` digerbang
 * hr.employment-types.manage karena ini operasi mutasi katalog.
 */
final class EmploymentCatalogController extends Controller
{
    public function indexEmploymentTypes(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $employmentTypes = EmploymentType::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $employmentTypes->map(
                fn (EmploymentType $employmentType) => [
                    'id' => (string) $employmentType->id,
                    'code' => $employmentType->code,
                    'name' => $employmentType->name,
                    'description' => $employmentType->description,
                    'is_active' => $employmentType->is_active,
                ],
            ),
        ]);
    }

    public function storeEmploymentType(StoreEmploymentTypeRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     code: string,
         *     name: string,
         *     description: string|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $employmentType = EmploymentType::create($payload);
        } catch (Throwable $exception) {
            Log::error(
                'EmploymentType creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'EMPLOYMENT_TYPE_CREATION_FAILED',
                message: 'Failed to persist EmploymentType record.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => (string) $employmentType->id,
                'code' => $employmentType->code,
                'name' => $employmentType->name,
                'description' => $employmentType->description,
                'is_active' => $employmentType->is_active,
            ],
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
