<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Modules\Core\Application\Identity\AuthenticateUser;
use Modules\Core\Domain\Identity\User;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Login global dengan email ATAU username; akun nonaktif ditolak (PRD-000 §6).
        Fortify::authenticateUsing(fn (Request $request): ?User => app(AuthenticateUser::class)->handle(
            $request->string(Fortify::username())->toString(),
            $request->string('password')->toString(),
        ));

        // Konfirmasi ulang password. Wajib callback sendiri: bawaan Fortify mencari user
        // berdasarkan kolom `fortify.username` (= "login"), yang bukan kolom di tabel users.
        Fortify::confirmPasswordsUsing(fn (User $user, ?string $password): bool => is_string($password)
            && $password !== ''
            && Hash::check($password, $user->password));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            // Per akun+IP (bawaan kit), ditambah batas per IP agar satu IP tidak bisa membanjiri
            // percobaan (dan audit log) dengan mengganti-ganti isian login (F4).
            return [
                Limit::perMinute(5)->by($throttleKey),
                Limit::perMinute(30)->by('ip|'.$request->ip()),
            ];
        });

    }
}
