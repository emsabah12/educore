<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Core\Contracts\Diagnostics\HealthCheckerInterface;

final class HealthCheckController extends Controller
{
    private HealthCheckerInterface $healthChecker;

    /**
     * Dependency Injection melalui Kontrak Abstraksi.
     */
    public function __construct(HealthCheckerInterface $healthChecker)
    {
        $this->healthChecker = $healthChecker;
    }

    /**
     * Menangani permintaan liveness dan readiness probe dari external monitoring tool.
     */
    public function __invoke(): JsonResponse
    {
        $healthStatus = $this->healthChecker->checkSystem();

        $httpStatusCode = $healthStatus['status'] === 'UP' ? 200 : 503;

        return response()->json($healthStatus, $httpStatusCode);
    }
}
