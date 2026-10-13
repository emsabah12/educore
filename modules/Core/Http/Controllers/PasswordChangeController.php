<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Domain\Identity\User;

/**
 * Halaman wajib ganti kata sandi untuk akun yang memakai sandi sementara (PRD-000 §4.7 F5a).
 *
 * Middleware EnsureAccountUsable mengarahkan akun bertanda must_change_password ke sini dan
 * menahan halaman lain sampai sandinya diganti.
 */
final class PasswordChangeController
{
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $this->user($request)->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/change-password', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }

        $request->validate([
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ], [], ['password' => 'kata sandi baru']);

        if (Hash::check($request->string('password')->toString(), $user->password)) {
            return back()->withErrors(['password' => 'Kata sandi baru tidak boleh sama dengan kata sandi sementara.']);
        }

        $user->changeOwnPassword($request->string('password')->toString());

        // ID sesi baru setelah kredensial berubah, mencegah session fixation.
        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kata sandi berhasil diganti.']);

        return redirect()->route('dashboard');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
