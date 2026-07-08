<?php

declare(strict_types=1);

namespace Modules\Academic\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\Academic\Events\CoursePublished;
use Modules\Core\Listeners\LogCoursePublication;

class AcademicServiceProvider extends ServiceProvider
{
    /**
     * Namespace default untuk controller modul Academic (jika dibutuhkan kelak).
     */
    protected string $moduleNamespace = 'Modules\Academic\Http\Controllers';
    
    /**
     * Peta Event & Listener khusus untuk internal/eksternal modul Academic.
     */
    protected array $listen = [
        CoursePublished::class => [
            LogCoursePublication::class,
        ],
    ];
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind sesuatu ke container untuk pembuktian
        $this->app->singleton('module.academic.loaded', function () {
            return true;
        });

        $this->registerMigrations();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Picu pemuatan routing sandbox untuk modul ini
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            Log::info('Academic Module has been dynamically booted with Sandbox Routing via Kernel!');
        }
    
        // Jika berjalan di CLI/Web, kita bisa memberikan tanda pengenal
        if (app()->runningInConsole()) {
            Log::info('Academic Module has been dynamically booted via Kernel!');
        }
    }
        /**
     * Daftarkan rute-rute milik modul Academic secara aman di dalam Sandbox Group.
     */
    private function registerRoutes(): void
    {
        $routePath = base_path('Modules/Academic/routes/web.php');

        // Proteksi: Hanya muat rute jika filenya benar-benar eksis
        if (file_exists($routePath)) {
            Route::middleware('web')               // Menggunakan stack middleware web standar Laravel
                ->namespace($this->moduleNamespace)
                ->prefix('academic')                // Mengamankan URL: /academic, /academic/courses, dll
                ->name('academic.')                // Mengamankan penamaan rute: route('academic.index')
                ->group($routePath);
        }
    }

    /**
     * Registrasikan peta event listener milik modul ke dalam sistem global Laravel Event.
     */
    private function registerEvents(): void
    {
        /** @var \Illuminate\Events\Dispatcher $dispatcher */
        $dispatcher = $this->app->make('events');

        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    /**
     * Daftarkan lokasi migrasi database internal milik modul secara otomatis.
     */
    private function registerMigrations(): void
    {
        $migrationPath = base_path('Modules/Academic/Database/Migrations');

        if (is_dir($migrationPath)) {
            $this->loadMigrationsFrom($migrationPath);
        }
    }
}