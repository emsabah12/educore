<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Authorization\ManageMemberAccess;
use Modules\Core\Domain\Authorization\Exceptions\AccessChangeException;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Penugasan & role tingkat Yayasan dari bagian "Akses login" di detail pegawai (PRD-000 §4.7 F5b).
 *
 * Hanya Admin Yayasan: core.memberships.manage di tingkat Yayasan (OD-21), selain itu 403.
 * Anggota/penugasan yang tidak ada di yayasan ini → 404. Pelanggaran aturan tampil di field form.
 */
final class MemberAccessController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly ManageMemberAccess $manageAccess,
    ) {}

    public function grantTenantRole(Request $request, string $membership): RedirectResponse
    {
        $this->authorize();

        $this->attempt(fn () => $this->manageAccess->grantTenantRole(
            $this->tenantId(),
            $membership,
            $request->string('role')->toString(),
        ));

        return $this->done('Role tingkat Yayasan dipasang. Berlaku saat pemiliknya membuka halaman berikutnya.');
    }

    public function revokeTenantRole(Request $request, string $membership): RedirectResponse
    {
        $this->authorize();

        $this->attempt(fn () => $this->manageAccess->revokeTenantRole(
            $this->tenantId(),
            $membership,
            $request->string('role')->toString(),
            $this->tenantContext->require()->membershipId,
        ));

        return $this->done('Role tingkat Yayasan dicabut.');
    }

    public function storeAssignment(Request $request, string $membership): RedirectResponse
    {
        $this->authorize();

        $this->attempt(fn () => $this->manageAccess->addAssignment(
            $this->tenantId(),
            $membership,
            $request->string('kind')->toString(),
            $request->filled('organization_id') ? $request->string('organization_id')->toString() : null,
            $request->filled('jenjang') ? $request->string('jenjang')->toString() : null,
            $request->string('role')->toString(),
        ));

        return $this->done('Penugasan ditambahkan. Berlaku saat pemiliknya membuka halaman berikutnya.');
    }

    public function revokeAssignment(string $membership, string $assignment): RedirectResponse
    {
        $this->authorize();

        $this->attempt(fn () => $this->manageAccess->revokeAssignment($this->tenantId(), $membership, $assignment));

        return $this->done('Penugasan dicabut.');
    }

    private function authorize(): void
    {
        $this->authorization->inspectTarget(CoreAccess::MEMBERSHIPS_MANAGE, null, null)->authorize();
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * Jalankan service; pelanggaran aturan tampil sebagai pesan di field form terkait.
     *
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (AccessChangeException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
