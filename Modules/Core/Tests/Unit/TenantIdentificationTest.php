<?php

namespace Modules\Core\Tests\Unit;

use Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Http\Middleware\IdentifyTenant;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantIdentificationTest extends TestCase
{
    use RefreshDatabase;

    public function testMiddlewareSuccessfullyBindsTenantContextOnValidSubdomain(): void
    {
        // 1. Arrange: Buat data tenant buatan di DB testing
        $tenant = Tenant::create([
            'name' => 'SDN Cerdas Mulia',
            'subdomain' => 'cerdasmulia',
            'is_active' => true
        ]);

        // Mock sebuah HTTP Request dengan Host mengarah ke subdomain tenant
        $request = Request::create('http://cerdasmulia.educore.test/login', 'GET');
        $request->headers->set('HOST', 'cerdasmulia.educore.test');

        // Ambil instance Context & Middleware dari container aplikasi
        $context = $this->app->make(TenantContextInterface::class);
        $middleware = new IdentifyTenant($context);

        // 2. Act: Jalankan request melewati handler middleware
        $response = $middleware->handle($request, function ($req) {
            return new Response('Passed Middleware');
        });

        // 3. Assert: Pastikan request lolos dan state context terisi dengan benar
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Passed Middleware', $response->getContent());
        $this->assertEquals($tenant->id, $context->getCurrentTenantId());
    }

    public function testMiddlewareAbortsWith404OnInvalidSubdomain(): void
    {
        // 1. Arrange: Request mengarah ke subdomain yang tidak terdaftar
        $request = Request::create('http://unknown.educore.test/login', 'GET');
        $request->headers->set('HOST', 'unknown.educore.test');

        $context = $this->app->make(TenantContextInterface::class);
        $middleware = new IdentifyTenant($context);

        // 2. Act & Assert: Pastikan melempar HttpException 404
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('School / Tenant not found or deactivated.');

        $middleware->handle($request, function ($req) {
            return new Response('Should Not Pass');
        });
    }
}
