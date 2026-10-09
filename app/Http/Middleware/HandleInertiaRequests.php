<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\TenantContext;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => fn (): ?array => $this->sharedUser($request),
            ],
            // Yayasan & lembaga kerja aktif; null di halaman tanpa konteks kerja (PRD-000 §6).
            // Dievaluasi saat halaman dirender, yaitu setelah middleware `work.context` berjalan.
            'context' => fn (): ?array => app(TenantContext::class)->get()?->toArray(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Data user yang dikirim ke browser. Daftar field dibuat eksplisit
     * (bukan seluruh model) supaya kolom sensitif tidak ikut terkirim.
     *
     * @return array{id: string, name: string, email: string, username: string|null, is_superadmin: bool, two_factor_enabled: bool}|null
     */
    private function sharedUser(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        // Nama milik Person (PRD-000 §5); dimuat eksplisit karena lazy loading dimatikan.
        $user->loadMissing('person');

        return [
            'id' => $user->id,
            'name' => $user->person->name,
            'email' => $user->email,
            'username' => $user->username,
            'is_superadmin' => $user->is_superadmin,
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
        ];
    }
}
