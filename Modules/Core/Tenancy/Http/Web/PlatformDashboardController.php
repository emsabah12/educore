<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class PlatformDashboardController extends Controller
{
    public function index(): View
    {
        return view('platform.dashboard');
    }
}
