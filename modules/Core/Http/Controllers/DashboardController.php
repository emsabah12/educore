<?php

namespace Modules\Core\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Dashboard\DashboardCards;

/**
 * Beranda: kartu ringkas dari modul (DashboardCards). Kosong → sambutan saja.
 */
final class DashboardController
{
    public function __invoke(DashboardCards $cards): Response
    {
        return Inertia::render('dashboard', [
            'cards' => $cards->resolve(),
        ]);
    }
}
