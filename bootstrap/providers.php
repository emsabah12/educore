<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    Modules\Core\Providers\CoreServiceProvider::class,
    Modules\Academic\Providers\AcademicServiceProvider::class,
];
