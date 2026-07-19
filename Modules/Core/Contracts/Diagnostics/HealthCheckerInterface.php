<?php

declare(strict_types=1);

namespace Modules\Core\Contracts\Diagnostics;

interface HealthCheckerInterface
{
    /**
     * Memeriksa kesehatan total ekosistem infrastruktur aplikasi.
     * 
     * @return array Mengembalikan array terstruktur berisi ['status' => 'UP|DOWN', 'components' => array]
     */
    public function checkSystem(): array;
}
