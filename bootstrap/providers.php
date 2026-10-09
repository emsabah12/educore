<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\Academic\AcademicServiceProvider;
use Modules\Core\CoreServiceProvider;
use Modules\HR\HRServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,

    // Modul EduCore — urutan mengikuti arah dependency (ADR-001 §2.2):
    // Core → HR → Academic
    CoreServiceProvider::class,
    HRServiceProvider::class,
    AcademicServiceProvider::class,
];
