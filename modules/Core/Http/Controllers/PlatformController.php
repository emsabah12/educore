<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Domain\Identity\User;

/**
 * Panel platform untuk Superadmin (PRD-000 §3). Isi panel dibangun di tahap berikutnya.
 */
final class PlatformController
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_superadmin) {
            abort(403);
        }

        return Inertia::render('platform/index');
    }
}
