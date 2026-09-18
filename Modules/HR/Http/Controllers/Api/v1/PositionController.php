<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Http\Requests\StorePositionRequest;
use Modules\HR\Models\Position;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * HR-002 §7 — Position catalog. Read/write tenant-scoped, mengikuti
 * pola CompensationComponentController persis (index + store saja
 * untuk rilis pertama ini). INV-HR-003: Position BUKAN authorization
 * role — lihat docblock model.
 */
final class PositionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $positions = Position::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $positions,
        ]);
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

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
            $position = Position::create($payload);
        } catch (Throwable $exception) {
            Log::error(
                'Position creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'POSITION_CREATION_FAILED',
                message: 'Failed to persist Position record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $position,
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
