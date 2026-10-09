<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Identity\UpdateUserProfile;

/**
 * Halaman profil milik pengguna sendiri.
 *
 * Fitur hapus akun mandiri sengaja tidak ada (PRD-000 OD-06): akun dinonaktifkan
 * oleh admin agar riwayat data (nilai, absensi, dll.) tetap utuh.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(ProfileUpdateRequest $request, UpdateUserProfile $updateUserProfile): RedirectResponse
    {
        /** @var array{name: string, email: string} $data */
        $data = $request->validated();

        $updateUserProfile->handle($request->user(), $data['name'], $data['email']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
