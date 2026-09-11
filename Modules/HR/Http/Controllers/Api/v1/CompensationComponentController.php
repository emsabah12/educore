<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Http\Requests\StoreCompensationComponentRequest;
use Modules\HR\Models\CompensationComponent;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HR-006 §7.2 — Compensation Component catalog. Read/write
 * tenant-scoped, mengikuti pola LeaveTypeController persis (index +
 * store saja untuk rilis pertama ini — belum ada update/deactivate,
 * bisa ditambah kalau ada kebutuhan nyata).
 */
final class CompensationComponentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $components = CompensationComponent::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $components,
        ]);
    }

    public function store(StoreCompensationComponentRequest $request): JsonResponse
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
         *     value_mode: string,
         *     unit_code: string|null,
         *     periodicity: string,
         *     description: string|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $component = CompensationComponent::create($payload);
        } catch (Throwable $exception) {
            Log::error(
                'CompensationComponent creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'COMPENSATION_COMPONENT_CREATION_FAILED',
                message: 'Failed to persist CompensationComponent record. Check that unit_code matches value_mode (HR-006 §7.2).',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $component,
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
