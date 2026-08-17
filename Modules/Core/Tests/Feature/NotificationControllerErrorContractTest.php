<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Validation\Validator;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;
use Modules\Core\Platform\Http\Controllers\Api\v1\NotificationController;
use Modules\Core\Platform\Http\Requests\SendNotificationRequest;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class NotificationControllerErrorContractTest extends TestCase
{
    private const TENANT_ID =
    '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

    private const USER_ID =
    '019f62f3-f5b5-7216-9578-0af9cb3b5b55';

    public function test_missing_tenant_context_uses_canonical_context_error(): void
    {
        $auditTrail = $this->createMock(
            AuditTrailServiceInterface::class,
        );

        $auditTrail
            ->expects($this->never())
            ->method('log');

        $controller = new NotificationController(
            $auditTrail,
        );

        $request = SendNotificationRequest::create(
            '/api/v1/core/notifications/dispatch',
            'POST',
        );

        $response = $controller->send(
            $request,
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' =>
                'AUTHENTICATION_CONTEXT_DENIED',
                'message' =>
                'Authentication context missing or invalid.',
            ],
            $response->getData(true),
        );
    }

    public function test_missing_user_context_uses_canonical_context_error(): void
    {
        $auditTrail = $this->createMock(
            AuditTrailServiceInterface::class,
        );

        $auditTrail
            ->expects($this->never())
            ->method('log');

        $controller = new NotificationController(
            $auditTrail,
        );

        $request = SendNotificationRequest::create(
            '/api/v1/core/notifications/dispatch',
            'POST',
        );

        $request->attributes->set(
            'authenticated_tenant_id',
            self::TENANT_ID,
        );

        $response = $controller->send(
            $request,
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' =>
                'AUTHENTICATION_CONTEXT_DENIED',
                'message' =>
                'Authentication context missing or invalid.',
            ],
            $response->getData(true),
        );
    }

    public function test_queue_dispatch_failure_uses_canonical_operational_error(): void
    {
        $auditTrail = $this->createMock(
            AuditTrailServiceInterface::class,
        );

        /*
         * Queue dispatch gagal sebelum audit boundary,
         * sehingga audit tidak boleh dijalankan.
         */
        $auditTrail
            ->expects($this->never())
            ->method('log');

        $dispatcher = $this->createMock(
            Dispatcher::class,
        );

        $dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(
                    static fn(
                        mixed $job,
                    ): bool =>
                    $job instanceof SendAsynchronousNotificationJob
                        && $job->getTenantId()
                        === self::TENANT_ID,
                ),
            )
            ->willThrowException(
                new RuntimeException(
                    'internal-queue-secret',
                ),
            );

        /*
         * PendingDispatch Laravel menyelesaikan dispatch melalui
         * Illuminate\Contracts\Bus\Dispatcher.
         *
         * Dengan mengganti binding ini kita dapat membuktikan
         * controller dispatch boundary tanpa membutuhkan database queue.
         */
        $this->app->instance(
            Dispatcher::class,
            $dispatcher,
        );

        $validator = $this->createMock(
            Validator::class,
        );

        $validator
            ->expects($this->once())
            ->method('validated')
            ->willReturn([
                'recipient' =>
                '089987654321',
                'body' =>
                'Queue failure contract.',
                'options' => [
                    'title' =>
                    'Queue Failure',
                ],
            ]);

        $request = SendNotificationRequest::create(
            '/api/v1/core/notifications/dispatch',
            'POST',
        );

        $request->attributes->set(
            'authenticated_tenant_id',
            self::TENANT_ID,
        );

        $request->attributes->set(
            'authenticated_user_id',
            self::USER_ID,
        );

        $request->setValidator(
            $validator,
        );

        $controller = new NotificationController(
            $auditTrail,
        );

        $response = $controller->send(
            $request,
        );

        $this->assertSame(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' =>
                'NOTIFICATION_DISPATCH_FAILED',
                'message' =>
                'Failed to queue notification.',
            ],
            $response->getData(true),
        );

        $this->assertStringNotContainsString(
            'internal-queue-secret',
            $response->getContent(),
        );
    }
}
