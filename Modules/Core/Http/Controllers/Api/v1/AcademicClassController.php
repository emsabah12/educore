<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Core\Contracts\Repository\AcademicClassRepositoryInterface;
use Throwable;

final class AcademicClassController extends Controller
{
    private AcademicClassRepositoryInterface $classRepository;

    public function __construct(AcademicClassRepositoryInterface $classRepository)
    {
        $this->classRepository = $classRepository;
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $perPage = (int) $request->query('per_page', '15');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $classes = $this->classRepository->getByTenantPaginated($tenantId, $perPage);

        return response()->json([
            'status' => 'success',
            'data' => $classes->items(),
            'meta' => [
                'current_page' => $classes->currentPage(),
                'last_page' => $classes->lastPage(),
                'per_page' => $classes->perPage(),
                'total' => $classes->total(),
            ]
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:50'],
            'tingkat' => ['required', 'string', 'max:20'],
            'is_active' => ['boolean']
        ]);

        try {
            $class = $this->classRepository->createForTenant($tenantId, $payload);

            return response()->json([
                'status' => 'success',
                'message' => 'Academic class created successfully.',
                'data' => $class
            ], 201);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to create academic class.'], 500);
        }
    }
}
