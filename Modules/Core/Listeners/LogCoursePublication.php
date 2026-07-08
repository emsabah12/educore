<?php

declare(strict_types=1);

namespace Modules\Core\Listeners;

use Modules\Academic\Events\CoursePublished;
use Illuminate\Support\Facades\Log;

class LogCoursePublication
{
    /**
     * Handle the event.
     */
    public function handle(CoursePublished $event): void
    {
        // Mencatat log enterprise untuk membuktikan event lintas-modul sukses ditangkap
        Log::info(sprintf(
            '[CROSS-MODULE EVENT] Core Kernel captured Academic Event! New Course Loaded: "%s" (%d Credits)',
            $event->courseName,
            $event->credits
        ));
    }
}