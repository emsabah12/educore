<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Discovery\ModuleDiscovery;
use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestLoader;
use Modules\Core\Manifest\ModuleManifestParser;
use 
Modules\Core\Registry\ModuleEventRegistry;
use Modules\Core\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleBootstrapService;
use Modules\Core\Services\ModuleLoader;
use Modules\Core\Services\ModuleManager;
use Modules\Core\Services\ModuleRepository;
use Modules\Core\Services\ModuleStateRepository;
use Modules\Core\Services\DependencyResolver;
use Modules\Core\Services\EventDiscoveryService;
use Modules\Core\Console\ModuleListCommand;
use Modules\Core\Console\ModuleStatusCommand;
use Modules\Core\Console\ModuleEnableCommand;
use Modules\Core\Console\ModuleDisableCommand;
use Modules\Core\Console\TestModuleLoaderCommand;
use Modules\Core\Console\KernelHealthCheckCommand;
use Modules\Core\Support\Uuid\UuidBlueprintMacro;
use Illuminate\Support\Facades\Log;

final class CoreServiceProvider extends ServiceProvider
{
    /**
     * Daftarkan seluruh layanan komponen platform ke dalam IoC Container.
     */
    public function register(): void
    {
        
        // Ikat ModuleEventRegistry sebagai objek singleton di container Laravel
        $this->app->singleton(ModuleEventRegistry::class, function () {
            return new ModuleEventRegistry();
        });

        // 1. Komponen Infrastruktur Dasar Sasis (Auto-wired Singletons)
        $this->app->singleton(ModuleDiscovery::class);
        $this->app->singleton(ModuleManifestLoader::class);
        $this->app->singleton(ModuleManifestParser::class);
        $this->app->singleton(ModuleDefinitionFactory::class);
        $this->app->singleton(ModuleLoader::class);
        $this->app->singleton(ModuleBootstrapService::class);

        // 2. Runtime State Repository dengan target file terisolasi
        $this->app->singleton(ModuleStateRepository::class, function (): ModuleStateRepository {
            return new ModuleStateRepository(
                storage_path('framework/modules.json')
            );
        });

        // 3. Source of Truth Metadata - Di-resolve murni sebagai objek kosong terlebih dahulu
        $this->app->singleton(ModuleRegistry::class, function (): ModuleRegistry {
            return new ModuleRegistry();
        });

        // 4. Abstraksi Lapisan Baca (Query Model) dengan Lazy Bootstrap Injection
        $this->app->singleton(ModuleRepository::class, function ($app): ModuleRepository {
            /** @var ModuleRegistry $registry */
            $registry = $app->make(ModuleRegistry::class);

            // Proteksi: Lakukan pemindaian (bootstrap) HANYA JIKA registry masih kosong
            if ($registry->count() === 0) {
                /** @var ModuleBootstrapService $bootstrapService */
                $bootstrapService = $app->make(ModuleBootstrapService::class);
                // Isi registry dari hasil pemindaian disk fisik secara lazy
                $discoveredRegistry = $bootstrapService->bootstrap(base_path('Modules'));
                
                // Pindahkan hasil discovery ke dalam singleton registry utama
                foreach ($discoveredRegistry->all() as $moduleDefinition) {
                    // Menggunakan ->name bukan ->getName() sesuai struktur Entity Anda
                    $moduleName = method_exists($moduleDefinition, 'getName') 
                        ? $moduleDefinition->getName() 
                        : $moduleDefinition->name;

                    if (!$registry->has($moduleName)) {
                        $registry->register($moduleDefinition);
                    }
                }
            }

            return new ModuleRepository($registry);
        });

        // 5. Abstraksi Lapisan Perubahan (Command Service Model)
        $this->app->singleton(ModuleManager::class, function ($app): ModuleManager {
            return new ModuleManager(
                $app->make(ModuleRepository::class),
                $app->make(ModuleStateRepository::class)
            );
        });

        if (method_exists($this, 'registerBlueprintMacros')) {
            $this->registerBlueprintMacros();
        };
        // JIT TRIGGER: Daftarkan Service Provider dari Modul-Modul yang Aktif 
    $this->registerActiveModules();
    $this->registerMigrations();
    // Daftarkan TenantServiceProvider secara internal
    // $this->app->register(\Modules\Core\Providers\TenantServiceProvider::class);
    // 1. Amankan pendaftaran Tenant Context Service Provider
    $this->app->register(TenantServiceProvider::class);
    // 2. Amankan pendaftaran Custom Auth Driver Service Provider
        $this->app->register(AuthServiceProvider::class);

        // 3. Modifikasi Konfigurasi Driver Autentikasi secara Dynamic Runtime (Bypass config/auth.php)
        $this->overrideAuthenticationConfig();
    }
    /**
     * Pindai modules.json dan daftarkan Service Provider milik modul yang berstatus ACTIVE.
     */
    private function registerActiveModules(): void
    {
        /** @var ModuleRepository $repository */
        $repository = $this->app->make(ModuleRepository::class);
        /** @var ModuleStateRepository $stateRepository */
        $stateRepository = $this->app->make(ModuleStateRepository::class);

        try {
            foreach ($repository->all() as $module) {
                $name = method_exists($module, 'getName') 
                    ? $module->getName() 
                    : $module->name;

                // Abaikan modul Core agar tidak mendaftarkan dirinya sendiri secara rekursif
                if (strtolower($name) === 'core') {
                    continue;
                }

                // Jika status runtime di modules.json adalah ENABLED/ACTIVE
                if ($stateRepository->isEnabled($name)) {
                    // Konvensi Namespace PSR-4
                    $studlyName = ucfirst($name);
                    $providerClass = sprintf('Modules\\%s\\Providers\\%sServiceProvider', $studlyName, $studlyName);

                    if (class_exists($providerClass)) {
                        $this->app->register($providerClass);
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Dynamic Module Registration Failed: ' . $e->getMessage());
        }
    }

    public function boot(): void
    {
        // Daftarkan sistem macro UUID v7 database secara global
        UuidBlueprintMacro::register();

        // DAFTARKAN CROSS-MODULE EVENT BINDING DI SINI (Prioritas Tertinggi Kernel)
        if (class_exists(\Modules\Academic\Events\CoursePublished::class) && class_exists(\Modules\Core\Listeners\LogCoursePublication::class)) {
            \Illuminate\Support\Facades\Event::listen(
                \Modules\Academic\Events\CoursePublished::class,
                \Modules\Core\Listeners\LogCoursePublication::class
            );
        }
        // Daftarkan seluruh perintah Artisan khusus milik modul Core jika berjalan di CLI
        if ($this->app->runningInConsole()) {
            $this->commands([
                KernelHealthCheckCommand::class,
                ModuleListCommand::class,
                ModuleStatusCommand::class,
                ModuleEnableCommand::class,
                ModuleDisableCommand::class,
                TestModuleLoaderCommand::class,
            ]);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Core\Console\TenantProvisionCommand::class,
            ]);
        }

        // BINDING EVENT LARAVEL SECARA NATIVE
        // Ambil objek registry event yang telah dikumpulkan selama fase bootstrap modul
        /** @var ModuleEventRegistry $eventRegistry */
        $eventRegistry = $this->app->make(ModuleEventRegistry::class);

        // Iterasikan map hasil temuan auto-discovery ke Event Engine Laravel
        foreach ($eventRegistry->getAll() as $eventClass => $listeners) {
            foreach ($listeners as $listenerClass) {
                Event::listen($eventClass, $listenerClass);
            }
        }

    }

    /**
     * Daftarkan custom macro extensions untuk skema Blueprint Database.
     */
    private function registerBlueprintMacros(): void
    {
        Blueprint::macro('uuidV7', function (string $column = 'id') {
            /** @var Blueprint $this */
            $blueprint = $this;
            return $this->uuid($column);
        });
    }

    private function registerMigrations(): void
    {
        $migrationPath = base_path('Modules/Core/Database/Migrations');

        if (is_dir($migrationPath)) {
            $this->loadMigrationsFrom($migrationPath);
        }
    }

    /**
     * Mengubah konfigurasi auth framework secara dinamis agar menggunakan tenant-eloquent.
     * Pendekatan ini menjaga file config/auth.php bawaan laravel tetap bersih.
     */
    private function overrideAuthenticationConfig(): void
    {
        // Mengamankan mapping agar provider 'users' beralih ke driver buatan kita
        config([
            'auth.providers.users.driver' => 'tenant-eloquent',
            'auth.providers.users.model' => \App\Models\User::class, // atau model user di level domain
        ]);

        Log::debug('Authentication driver dynamically set to [tenant-eloquent] via CoreServiceProvider.');
    }
}