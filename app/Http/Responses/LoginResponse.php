<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Sesudah login (termasuk lewat kode dua langkah) selalu ke Dashboard, bukan ke halaman yang
 * terakhir dibuka sebelum sesi habis ("intended URL").
 *
 * Alasannya: halaman lama itu bisa milik akun lain yang login di browser yang sama, atau butuh
 * lembaga kerja yang berbeda dari lembaga kerja bawaan sesudah login. Akibatnya pengguna langsung
 * mendapat 403 "Anda tidak memiliki izin" walaupun login-nya benar. Dari Dashboard pengguna bisa
 * memilih lembaga kerja lalu membuka menu yang memang tersedia baginya.
 */
final class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $request->session()->forget('url.intended');

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false])
            : redirect()->to(Fortify::redirects('login'));
    }
}
