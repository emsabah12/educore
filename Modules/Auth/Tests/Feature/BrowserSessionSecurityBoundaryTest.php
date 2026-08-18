<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

final class BrowserSessionSecurityBoundaryTest extends TestCase
{
    public function test_csrf_bootstrap_route_uses_stateful_web_boundary(): void
    {
        $route = Route::getRoutes()->getByName(
            'api.v1.browser.session.csrf',
        );

        $this->assertNotNull($route);
        $this->assertSame(
            '/api/v1/browser/session/csrf',
            '/'.ltrim($route->uri(), '/'),
        );
        $this->assertContains('web', $route->middleware());
        $this->assertNotContains('api', $route->middleware());
    }

    public function test_csrf_bootstrap_emits_hardened_session_and_xsrf_cookies(): void
    {
        config([
            'session.driver' => 'array',
            'session.cookie' => '__Host-educore-session',
            'session.secure' => true,
            'session.http_only' => true,
            'session.path' => '/',
            'session.domain' => null,
            'session.same_site' => 'strict',
            'session.partitioned' => false,
        ]);

        $response = $this->get('/api/v1/browser/session/csrf');

        $response->assertNoContent();

        $sessionCookie = $this->cookieNamed(
            $response->headers->getCookies(),
            '__Host-educore-session',
        );
        $xsrfCookie = $this->cookieNamed(
            $response->headers->getCookies(),
            'XSRF-TOKEN',
        );

        $this->assertTrue($sessionCookie->isSecure());
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertSame('/', $sessionCookie->getPath());
        $this->assertNull($sessionCookie->getDomain());
        $this->assertSame('strict', $sessionCookie->getSameSite());

        $this->assertTrue($xsrfCookie->isSecure());
        $this->assertFalse($xsrfCookie->isHttpOnly());
        $this->assertSame('/', $xsrfCookie->getPath());
        $this->assertNull($xsrfCookie->getDomain());
        $this->assertSame('strict', $xsrfCookie->getSameSite());
    }

    public function test_enforced_request_forgery_middleware_rejects_cross_site_mutation(): void
    {
        $request = $this->stateChangingRequest(
            secFetchSite: 'cross-site',
        );

        $this->expectException(TokenMismatchException::class);

        $this->requestForgeryMiddleware()->handle(
            $request,
            static fn (): Response => response()->noContent(),
        );
    }

    public function test_enforced_request_forgery_middleware_accepts_same_origin_mutation(): void
    {
        $request = $this->stateChangingRequest(
            secFetchSite: 'same-origin',
        );

        $response = $this->requestForgeryMiddleware()->handle(
            $request,
            static fn (): Response => response()->noContent(),
        );

        $this->assertSame(204, $response->getStatusCode());
    }

    /**
     * @param  list<Cookie>  $cookies
     */
    private function cookieNamed(array $cookies, string $name): Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        self::fail(sprintf('Cookie [%s] was not emitted.', $name));
    }

    private function stateChangingRequest(string $secFetchSite): Request
    {
        $request = Request::create(
            '/api/v1/browser/auth/test-mutation',
            'POST',
            server: [
                'HTTP_SEC_FETCH_SITE' => $secFetchSite,
            ],
        );

        $session = new Store(
            'educore-test-session',
            new ArraySessionHandler(120),
        );
        $session->start();

        $request->setLaravelSession($session);

        return $request;
    }

    private function requestForgeryMiddleware(): PreventRequestForgery
    {
        return new class($this->app, $this->app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
    }
}
