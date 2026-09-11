<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Application\AuthenticationChannel;
use Modules\Auth\Application\Services\GlobalAuthenticationService;
use Modules\Core\Tenancy\Http\Requests\Web\PlatformLoginRequest;

final class PlatformAuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOCKOUT_DECAY_SECONDS = 60;

    public function __construct(
        private readonly GlobalAuthenticationService $authenticationService,
    ) {}

    public function showLoginForm(): View|RedirectResponse
    {
        $user = Auth::guard('web')->user();

        if ($user !== null && (bool) $user->is_superadmin) {
            return redirect()->route('platform.dashboard');
        }

        return view('platform.auth.login');
    }

    /**
     * §Keamanan: pesan gagal SELALU generik, baik untuk kredensial salah,
     * akun yang benar tapi bukan superadmin, MAUPUN penguncian akibat
     * rate limit — tidak pernah membocorkan status akun yang sesungguhnya.
     *
     * Kunci pembatasan gabungan `identifier + IP` (pola Fortify): mencegah
     * brute-force pada SATU akun dari SATU sumber, tanpa mengunci
     * pengguna sah lain yang kebetulan berbagi jaringan (mis. satu
     * kantor/sekolah di belakang IP publik yang sama).
     */
    public function login(PlatformLoginRequest $request): RedirectResponse
    {
        /** @var array{identifier: string, password: string} $credentials */
        $credentials = $request->validated();

        $throttleKey = $this->throttleKey(
            $credentials['identifier'],
            $request->ip(),
        );

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $secondsRemaining = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'identifier' => sprintf(
                    'Terlalu banyak percobaan. Coba lagi dalam %d detik.',
                    $secondsRemaining,
                ),
            ]);
        }

        $identity = $this->authenticationService->authenticate(
            $credentials['identifier'],
            $credentials['password'],
            AuthenticationChannel::BROWSER_SESSION,
        );

        if ($identity === null || ! $identity->isSuperadmin) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'identifier' => 'Kredensial tidak valid.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        Auth::guard('web')->loginUsingId($identity->userId);
        $request->session()->regenerate();

        return redirect()->intended(route('platform.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }

    private function throttleKey(string $identifier, ?string $ip): string
    {
        return Str::transliterate(
            Str::lower($identifier).'|'.($ip ?? 'unknown'),
        );
    }
}
