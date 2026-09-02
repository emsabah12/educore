<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Http\Request;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Modules\HR\Http\Controllers\Api\v1\EmployeeManagementController;
use Modules\HR\Http\Requests\StoreEmployeeRequest;
use Modules\HR\Services\EmployeeProvisioningService;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class EmployeeManagementControllerErrorContractTest extends TestCase
{
    private const TENANT_ID = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

    private const USER_ID = '019f62f3-f5b5-7216-9578-0af9cb3b5b55';

    public function test_index_missing_tenant_context_uses_canonical_context_error(): void
    {
        $controller = $this->makeController();

        $request = Request::create(
            '/api/v1/hr/employees',
            'GET',
        );

        $response = $controller->index($request);

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'AUTHENTICATION_CONTEXT_DENIED',
                'message' => 'Authentication context missing or invalid.',
            ],
            $response->getData(true),
        );
    }

    public function test_store_missing_tenant_context_uses_canonical_context_error(): void
    {
        $auditTrail = $this->createMock(AuditTrailServiceInterface::class);
        $auditTrail->expects($this->never())->method('log');

        $controller = $this->makeController(
            auditTrail: $auditTrail,
        );

        $request = StoreEmployeeRequest::create(
            '/api/v1/hr/employees',
            'POST',
        );

        $response = $controller->store($request);

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'AUTHENTICATION_CONTEXT_DENIED',
                'message' => 'Authentication context missing or invalid.',
            ],
            $response->getData(true),
        );
    }

    public function test_store_malformed_non_uuid_tenant_context_uses_canonical_context_error(): void
    {
        $controller = $this->makeController();

        $request = StoreEmployeeRequest::create(
            '/api/v1/hr/employees',
            'POST',
        );

        $request->attributes->set(
            'authenticated_tenant_id',
            'not-a-uuid',
        );

        $response = $controller->store($request);

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );
        $this->assertSame(
            'AUTHENTICATION_CONTEXT_DENIED',
            $response->getData(true)['code'],
        );
    }

    private function makeController(
        ?EmployeeRepositoryInterface $employeeRepository = null,
        ?PersonRepositoryInterface $personRepository = null,
        ?AuditTrailServiceInterface $auditTrail = null,
    ): EmployeeManagementController {
        $employeeRepository ??= $this->createMock(EmployeeRepositoryInterface::class);
        $personRepository ??= $this->createMock(PersonRepositoryInterface::class);
        $auditTrail ??= $this->createMock(AuditTrailServiceInterface::class);

        return new EmployeeManagementController(
            $employeeRepository,
            new EmployeeProvisioningService(
                $personRepository,
                $employeeRepository,
            ),
            $auditTrail,
        );
    }
}
