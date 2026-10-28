<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Guardian\GuardianAccounts;
use Modules\Academic\Application\Student\StudentManageAccess;
use Modules\Academic\Application\Student\StudentVisibility;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Core\Application\Identity\IssuedPassword;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Akun login orang tua/wali dari detail siswa (PRD-002 §21.1, AC-7a): buat, atur ulang sandi,
 * nonaktifkan, aktifkan. Hak akses sama dengan kelola orang tua/wali (OD-AC-09): siswa tidak
 * terlihat → 404; tidak boleh mengelola → 403.
 *
 * Kata sandi sementara dikirim lewat flash Inertia `issuedPassword` (tampil sekali, tidak disimpan).
 */
final class GuardianAccountController
{
    public function __construct(
        private readonly StudentVisibility $visibility,
        private readonly StudentManageAccess $access,
        private readonly TenantContext $tenantContext,
        private readonly GuardianAccounts $accounts,
    ) {}

    public function store(string $student, string $guardian): RedirectResponse
    {
        $this->authorize($student);

        $issued = $this->attempt(fn (): IssuedPassword => $this->accounts->provision($this->tenantId(), $student, $guardian));

        return $this->withPassword($issued, 'Akun login orang tua/wali dibuat.');
    }

    public function resetPassword(Request $request, string $student, string $guardian): RedirectResponse
    {
        $this->authorize($student);

        $issued = $this->attempt(fn (): IssuedPassword => $this->accounts->resetPassword(
            $this->tenantId(),
            $student,
            $guardian,
            $request->hasSession() ? $request->session()->getId() : null,
        ));

        return $this->withPassword($issued, 'Kata sandi diatur ulang. Sesi login lama orang tua/wali ini diakhiri.');
    }

    public function deactivate(Request $request, string $student, string $guardian): RedirectResponse
    {
        $this->authorize($student);

        /** @var User $actor */
        $actor = $request->user();
        $this->attempt(fn () => $this->accounts->setActive($this->tenantId(), $student, $guardian, false, $actor));

        return $this->done('Akun dinonaktifkan; orang tua/wali ini tidak bisa login.');
    }

    public function activate(Request $request, string $student, string $guardian): RedirectResponse
    {
        $this->authorize($student);

        /** @var User $actor */
        $actor = $request->user();
        $this->attempt(fn () => $this->accounts->setActive($this->tenantId(), $student, $guardian, true, $actor));

        return $this->done('Akun diaktifkan kembali.');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function authorize(string $studentId): void
    {
        if (! $this->visibility->canSee($studentId, AcademicAccess::STUDENTS_VIEW)) {
            abort(404);
        }

        if (! $this->access->canManageGuardians($studentId)) {
            abort(403);
        }
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (StudentException $exception) {
            throw ValidationException::withMessages(['account' => $exception->getMessage()]);
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
