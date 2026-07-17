<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\Core\Contracts\Repository\AcademicSubjectRepositoryInterface;
use Throwable;

final class AcademicSubjectController extends Controller
{
    private AcademicSubjectRepositoryInterface $subjectRepository;

    public function __construct(AcademicSubjectRepositoryInterface $subjectRepository)
    {
        $this->subjectRepository = $subjectRepository;
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $perPage = (int) $request->query('per_page', '15');

        if (! $tenantId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Tenant context missing.'], 401);
        }

        $subjects = $this->subjectRepository->getByTenantPaginated($tenantId, $perPage);

        return response()->json([
            'status' => 'success',
            'data' => $subjects->items(),
            'meta' => [
                'current_page' => $subjects->currentPage(),
                'last_page' => $subjects->lastPage(),
                'per_page' => $subjects->perPage(),
                'total' => $subjects->total(),
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
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:50'],
            'category' => ['required', 'string', 'in:NASIONAL,MUATAN_LOKAL,PESANTREN'],
            'is_active' => ['boolean']
        ]);

        try {
            $subject = $this->subjectRepository->createForTenant($tenantId, $payload);

            return response()->json([
                'status' => 'success',
                'message' => 'Academic subject created successfully.',
                'data' => $subject
            ], 201);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to create academic subject.'], 500);
        }
    }
}
