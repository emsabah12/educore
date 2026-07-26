<?php

namespace Modules\Core\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class IdentifyTenant
{
    /**
     * Menginjeksikan TenantContextInterface via Constructor Injection.
     */
    public function __construct(
        protected TenantContextInterface $tenantContext
    ) {}

    /**
     * Tangani request yang masuk untuk mengidentifikasi tenant via subdomain.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Ambil host dari request (misal: sdn01.educore.test)
        $host = $request->getHost();

        // 2. Ekstrak bagian subdomain paling kiri
        // Asumsi: format lokal 'subdomain.domain.com' atau 'subdomain.localhost'
        $hostParts = explode('.', $host);

        // Proteksi jika diakses tanpa subdomain (misal murni localhost atau ip address)
        if (count($hostParts) < 2 || $hostParts[0] === 'www') {
            Log::warning('Tenant identification bypassed: Host pattern does not specify a valid subdomain.', ['host' => $host]);
            return $next($request);
        }

        $subdomain = $hostParts[0];

        // 3. Query pencarian ke database master tenants yang berstatus aktif
        $tenant = Tenant::where('subdomain', $subdomain)
            ->where('is_active', true)
            ->first();

        if (! $tenant) {
            Log::error('Tenant connection rejected: Subdomain not registered or inactive.', ['subdomain' => $subdomain]);

            // Mengembalikan respon proteksi HTTP 404/Aborted demi keamanan
            abort(Response::HTTP_NOT_FOUND, 'School / Tenant not found or deactivated.');
        }

        // 4. Kunci data tenant ke dalam In-Memory State Store (Singleton)
        $this->tenantContext->setCurrentTenant($tenant);

        return $next($request);
    }
}
