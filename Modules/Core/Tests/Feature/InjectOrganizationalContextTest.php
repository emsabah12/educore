<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Http\Request;
use Modules\Core\Organization\Context\OrganizationalContext;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextResolverInterface;
use Modules\Core\Organization\Exceptions\OrganizationalContextException;
use Modules\Core\Organization\Http\Middleware\InjectOrganizationalContext;
use Modules\Core\Support\Uuid\UuidV7;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class InjectOrganizationalContextTest extends TestCase
{
    /** @var OrganizationalContextResolverInterface&MockObject */
    private OrganizationalContextResolverInterface $resolver;

    private OrganizationalContextInterface $organizationalContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = $this->createMock(
            OrganizationalContextResolverInterface::class,
        );

        $this->app->instance(
            OrganizationalContextResolverInterface::class,
            $this->resolver,
        );

        $this->organizationalContext = $this->app->make(
            OrganizationalContextInterface::class,
        );

        $this->organizationalContext->clear();
    }

    protected function tearDown(): void
    {
        $this->organizationalContext->clear();

        parent::tearDown();
    }

    public function test_valid_header_resolves_context_for_downstream_and_clears_it_after_request(): void
    {
        $context = $this->contextFixture();

        $this->resolver
            ->expects($this->once())
            ->method('resolve')
            ->with($context->assignmentId)
            ->willReturnCallback(
                function () use ($context): OrganizationalContext {
                    $this->organizationalContext->setCurrentContext(
                        $context,
                    );

                    return $context;
                },
            );

        $request = $this->requestWithAssignment(
            $context->assignmentId,
        );

        $response = $this->middleware()->handle(
            $request,
            function (Request $request) use ($context): Response {
                $this->assertSame(
                    $context,
                    $this->organizationalContext->getCurrentContext(),
                );

                $this->assertSame(
                    $context->assignmentId,
                    $request->header(
                        InjectOrganizationalContext::HEADER,
                    ),
                );

                return response()->json([
                    'status' => 'success',
                ]);
            },
        );

        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $this->assertNull(
            $this->organizationalContext->getCurrentContext(),
        );
    }

    public function test_missing_header_fails_closed_and_clears_stale_context(): void
    {
        $this->organizationalContext->setCurrentContext(
            $this->contextFixture(),
        );

        $this->resolver
            ->expects($this->never())
            ->method('resolve');

        $nextWasCalled = false;

        $response = $this->middleware()->handle(
            Request::create('/api/protected', 'GET'),
            static function () use (&$nextWasCalled): Response {
                $nextWasCalled = true;

                return response()->json([]);
            },
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            'ORGANIZATIONAL_CONTEXT_REQUIRED',
            $response->getData(true)['code'] ?? null,
        );

        $this->assertFalse($nextWasCalled);

        $this->assertNull(
            $this->organizationalContext->getCurrentContext(),
        );
    }

    public function test_malformed_assignment_identifier_is_rejected_before_resolution(): void
    {
        $this->resolver
            ->expects($this->never())
            ->method('resolve');

        $response = $this->middleware()->handle(
            $this->requestWithAssignment(
                'not-a-uuid-v7',
            ),
            static fn(): Response => response()->json([]),
        );

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );

        $this->assertSame(
            'INVALID_ORGANIZATIONAL_ASSIGNMENT_ID',
            $response->getData(true)['code'] ?? null,
        );

        $this->assertNull(
            $this->organizationalContext->getCurrentContext(),
        );
    }

    public function test_denied_context_returns_safe_forbidden_response_and_clears_state(): void
    {
        $assignmentId = UuidV7::generate();

        $this->organizationalContext->setCurrentContext(
            $this->contextFixture(),
        );

        $this->resolver
            ->expects($this->once())
            ->method('resolve')
            ->with($assignmentId)
            ->willThrowException(
                new OrganizationalContextException(
                    'Internal assignment ownership detail.',
                ),
            );

        $response = $this->middleware()->handle(
            $this->requestWithAssignment(
                $assignmentId,
            ),
            static fn(): Response => response()->json([]),
        );

        $payload = $response->getData(true);

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            'ORGANIZATIONAL_CONTEXT_DENIED',
            $payload['code'] ?? null,
        );

        $this->assertStringNotContainsString(
            'Internal assignment ownership detail.',
            (string) ($payload['message'] ?? ''),
        );

        $this->assertNull(
            $this->organizationalContext->getCurrentContext(),
        );
    }

    public function test_unexpected_resolution_failure_returns_generic_server_error(): void
    {
        $assignmentId = UuidV7::generate();

        $this->resolver
            ->expects($this->once())
            ->method('resolve')
            ->with($assignmentId)
            ->willThrowException(
                new RuntimeException(
                    'Simulated infrastructure failure.',
                ),
            );

        $response = $this->middleware()->handle(
            $this->requestWithAssignment(
                $assignmentId,
            ),
            static fn(): Response => response()->json([]),
        );

        $payload = $response->getData(true);

        $this->assertSame(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $response->getStatusCode(),
        );

        $this->assertSame(
            'ORGANIZATIONAL_CONTEXT_RESOLUTION_FAILED',
            $payload['code'] ?? null,
        );

        $this->assertStringNotContainsString(
            'Simulated infrastructure failure.',
            (string) ($payload['message'] ?? ''),
        );

        $this->assertNull(
            $this->organizationalContext->getCurrentContext(),
        );
    }

    public function test_downstream_exception_does_not_leak_resolved_context(): void
    {
        $context = $this->contextFixture();

        $this->resolver
            ->expects($this->once())
            ->method('resolve')
            ->with($context->assignmentId)
            ->willReturnCallback(
                function () use ($context): OrganizationalContext {
                    $this->organizationalContext->setCurrentContext(
                        $context,
                    );

                    return $context;
                },
            );

        $downstreamException = new RuntimeException(
            'Simulated downstream failure.',
        );

        try {
            $this->middleware()->handle(
                $this->requestWithAssignment(
                    $context->assignmentId,
                ),
                function () use ($downstreamException): never {
                    $this->assertNotNull(
                        $this->organizationalContext->getCurrentContext(),
                    );

                    throw $downstreamException;
                },
            );

            $this->fail(
                'Expected downstream exception was not thrown.',
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                $downstreamException,
                $exception,
            );
        }

        $this->assertNull(
            $this->organizationalContext->getCurrentContext(),
        );
    }

    private function middleware(): InjectOrganizationalContext
    {
        return $this->app->make(
            InjectOrganizationalContext::class,
        );
    }

    private function requestWithAssignment(
        string $assignmentId,
    ): Request {
        return Request::create(
            '/api/protected',
            'GET',
            server: [
                'HTTP_X_EDUCORE_ORGANIZATIONAL_ASSIGNMENT_ID' =>
                $assignmentId,
            ],
        );
    }

    private function contextFixture(): OrganizationalContext
    {
        return new OrganizationalContext(
            tenantId: UuidV7::generate(),
            membershipId: UuidV7::generate(),
            assignmentId: UuidV7::generate(),
            organizationId: UuidV7::generate(),
            organizationUnitId: null,
        );
    }
}
