<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Identity\IssuedPassword;
use Modules\Core\Application\Identity\ManageLoginAccount;
use Modules\Core\Domain\Identity\Exceptions\AccountException;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Aksi akun login dari bagian "Akses login" di detail pegawai (PRD-000 §4.7 F5a).
 *
 * Hanya Admin Yayasan: core.memberships.manage di tingkat Yayasan (OD-21), selain itu 403.
 * Anggota yang tidak ada di yayasan ini → 404. Pelanggaran aturan tampil di field form terkait.
 *
 * Kata sandi sementara dikirim lewat flash Inertia `issuedPassword`: tampil sekali di halaman
 * berikutnya, tidak masuk props halaman maupun riwayat browser, dan tidak dicatat di log.
 */
final class LoginAccountController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly ManageLoginAccount $manageLoginAccount,
    ) {}

    public function store(Request $request, string $membership): RedirectResponse
    {
        $this->authorize();

        $issued = $this->attempt(fn (): IssuedPassword => $this->manageLoginAccount->provision(
            $this->tenantId(),
            $membership,
            $request->string('username')->toString(),
            $request->filled('email') ? $request->string('email')->toString() : null,
        ));

        return $this->withPassword($issued, 'Akun login dibuat. Serahkan kata sandi sementara kepada pemiliknya.');
    }

    public function resetPassword(Request $request, string $membership): RedirectResponse
    {
        $this->authorize();

        $issued = $this->attempt(fn (): IssuedPassword => $this->manageLoginAccount->resetPassword(
            $this->tenantId(),
            $membership,
            $request->hasSession() ? $request->session()->getId() : null,
        ));

        return $this->withPassword($issued, 'Kata sandi diatur ulang. Sesi login lain milik akun ini sudah diakhiri.');
    }

    public function deactivate(Request $request, string $membership): RedirectResponse
    {
        $this->authorize();

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $this->attempt(fn () => $this->manageLoginAccount->deactivate($this->tenantId(), $membership, $actor));

        return $this->done('Akun login dinonaktifkan. Pemiliknya tidak bisa masuk lagi.');
    }

    public function activate(string $membership): RedirectResponse
    {
        $this->authorize();

        $this->attempt(fn () => $this->manageLoginAccount->activate($this->tenantId(), $membership));

        return $this->done('Akun login diaktifkan kembali.');
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
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (AccountException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function withPassword(IssuedPassword $issued, string $message): RedirectResponse
    {
        Inertia::flash('issuedPassword', $issued->toFlash());

        return $this->done($message);
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
