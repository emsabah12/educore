<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Application\AuthenticationChannel;
use Modules\Auth\Application\Services\GlobalAuthenticationService;
use Modules\Core\Tenancy\Http\Requests\Web\PlatformLoginRequest;

final class PlatformAuthController extends Controller
{
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
     * §Keamanan: pesan gagal SELALU generik, baik untuk kredensial salah
     * MAUPUN akun yang benar tapi bukan superadmin — tidak pernah
     * membocorkan "akun Anda benar tapi tidak berwenang", karena itu
     * sendiri adalah informasi (mengonfirmasi akun ada).
     */
    public function login(PlatformLoginRequest $request): RedirectResponse
    {
        /** @var array{identifier: string, password: string} $credentials */
        $credentials = $request->validated();

        $identity = $this->authenticationService->authenticate(
            $credentials['identifier'],
            $credentials['password'],
            AuthenticationChannel::BROWSER_SESSION,
        );

        if ($identity === null || ! $identity->isSuperadmin) {
            throw ValidationException::withMessages([
                'identifier' => 'Kredensial tidak valid.',
            ]);
        }

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
}
