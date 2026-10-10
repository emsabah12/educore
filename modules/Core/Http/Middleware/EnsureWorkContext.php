<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Context\WorkContextResolver;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wajib dipasang (alias `work.context`) di semua route yang bekerja di dalam yayasan.
 *
 * Setiap request: tentukan & validasi ulang konteks kerja. Bila belum lengkap atau
 * sudah tidak sah (membership dicabut, yayasan disuspend, penugasan dinonaktifkan),
 * pengguna diarahkan ke halaman yang sesuai (PRD-000 §6).
 */
final class EnsureWorkContext
{
    public function __construct(
        private readonly WorkContextResolver $resolver,
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Jangan pernah membawa konteks dari request sebelumnya (mis. pada server yang
        // memakai ulang proses PHP); konteks selalu dibentuk ulang dari nol.
        $this->tenantContext->clear();

        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $resolution = $this->resolver->resolve($user, $request->session());

        if ($resolution->context === null) {
            return redirect()->route($resolution->redirectRoute ?? WorkContextResolver::ROUTE_UNREGISTERED);
        }

        $this->tenantContext->set($resolution->context);

        // Hak akses selalu dihitung ulang dari database untuk konteks request ini (PRD-000 §7.1).
        $this->authorization->flush();

        return $next($request);
    }
}
