<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Context\AuthorizationContext;
use Modules\Core\Authorization\Contracts\AuthorizationContextInterface;
use Modules\Core\Authorization\Services\AuthorizationService;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Services\AuthorizationContextResolver;
use Modules\Core\Authorization\Repositories\Contracts\PermissionRepositoryInterface;
use Modules\Core\Authorization\Repositories\EloquentPermissionRepository;
use Modules\Core\Authorization\Repositories\EloquentMembershipRepository;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Authorization\Repositories\EloquentMembershipRoleRepository;
use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;
use Modules\Core\Authorization\Repositories\EloquentRolePermissionRepository;
use Modules\Core\Authorization\Contracts\AccessCheckerInterface;
use Modules\Core\Authorization\Services\AccessChecker;
use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Authorization\Services\MembershipContextResolver;
use Modules\Core\Platform\Discovery\ModuleDiscovery;
use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestLoader;
use Modules\Core\Manifest\ModuleManifestParser;
use Modules\Core\Platform\Module\Events\ModuleEventRegistry;
use Modules\Core\Platform\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleBootstrapService;
use Modules\Core\Platform\Module\Services\ModuleLoader;
use Modules\Core\Platform\Module\Services\ModuleManager;
use Modules\Core\Services\ModuleRepository;
use Modules\Core\Services\ModuleStateRepository;
use Modules\Core\Services\DependencyResolver;
use Modules\Core\Services\EventDiscoveryService;
use Modules\Core\Person\Repositories\EloquentPersonRepository;
use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\Core\Person\Contracts\PersonLifecycleEventRepositoryInterface;
use Modules\Core\Person\Repositories\EloquentPersonLifecycleEventRepository;
use Modules\Core\Platform\Console\ModuleListCommand;
use Modules\Core\Platform\Console\ModuleStatusCommand;
use Modules\Core\Platform\Console\ModuleEnableCommand;
use Modules\Core\Platform\Console\ModuleDisableCommand;
use Modules\Core\Tests\Console\TestModuleLoaderCommand;
use Modules\Core\Platform\Console\KernelHealthCheckCommand;
use Modules\Core\Listeners\QueueWatchdogListener;
use Modules\Core\Support\Uuid\UuidBlueprintMacro;
use Modules\Core\Shared\Database\TransactionManager;
use Modules\Core\Shared\Repositories\Contracts\TransactionManagerInterface;
use Modules\Core\Shared\Contracts\UnitOfWorkInterface;
use Modules\Core\Shared\UnitOfWork\UnitOfWork;
use Modules\Core\Shared\Contracts\CommandBusInterface;
use Modules\Core\Shared\Bus\CommandBus;
use Modules\Core\Shared\Bus\CommandHandlerResolver;
use Modules\Core\Shared\Bus\QueryHandlerResolver;
use Modules\Core\Shared\Bus\QueryBus;
use Modules\Core\Shared\Contracts\QueryBusInterface;
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
        // 3. Daftarkan Route Service Provider milik Core
        $this->app->register(RouteServiceProvider::class);

        $this->app->singleton(
            AuthorizationServiceInterface::class,
            AuthorizationService::class
        );

        $this->app->singleton(
            MembershipContextResolverInterface::class,
            MembershipContextResolver::class,
        );

        $this->app->singleton(
            AuthorizationContextResolverInterface::class,
            AuthorizationContextResolver::class,
        );

        $this->app->bind(
            AuthorizationContextInterface::class,
            AuthorizationContext::class,
        );

        $this->app->bind(
            PermissionRepositoryInterface::class,
            EloquentPermissionRepository::class,
        );

        $this->app->bind(
            MembershipRepositoryInterface::class,
            EloquentMembershipRepository::class,
        );

        $this->app->bind(
            MembershipRoleRepositoryInterface::class,
            EloquentMembershipRoleRepository::class,
        );

        $this->app->bind(
            RolePermissionRepositoryInterface::class,
            EloquentRolePermissionRepository::class,
        );

        $this->app->singleton(
            AccessCheckerInterface::class,
            AccessChecker::class,
        );

        $this->app->singleton(
            PersonRepositoryInterface::class,
            EloquentPersonRepository::class,
        );

        $this->app->singleton(
            PersonLifecycleEventRepositoryInterface::class,
            EloquentPersonLifecycleEventRepository::class,
        );

        $this->app->singleton(
            \Modules\Core\Person\Contracts\PersonLifecycleServiceInterface::class,
            \Modules\Core\Person\Services\PersonLifecycleService::class,
        );

        $this->app->singleton(
            TransactionManagerInterface::class,
            TransactionManager::class,
        );

        $this->app->singleton(
            UnitOfWorkInterface::class,
            UnitOfWork::class,
        );

        /*
         * Command Bus
         */
        $this->app->singleton(
            CommandBusInterface::class,
            CommandBus::class,
        );

        /*
         * Command Handler Resolver
         *
         * Resolver bertanggung jawab menemukan dan membuat
         * handler berdasarkan command yang diterima.
         */
        $this->app->singleton(
            CommandHandlerResolver::class,
        );

        /*
         * Query Handler Resolver
         *
         * Resolver bertanggung jawab menemukan dan membuat
         * handler berdasarkan query yang diterima.
         */
        $this->app->singleton(
            QueryHandlerResolver::class,
        );

        /*
         * Query Handler Resolver
         *
         */
        $this->app->singleton(
            QueryBusInterface::class,
            QueryBus::class,
        );
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

        $this->app->singleton(
            \Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface::class,
            \Modules\Core\Services\Auth\DatabaseAuditTrailService::class
        );

        $this->app->singleton(
            \Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface::class,
            \Modules\Core\Notification\Channels\WhatsAppNotificationChannel::class
        );

        $this->app->singleton(
            \Modules\Core\Platform\Health\Contracts\Diagnostics\HealthCheckerInterface::class,
            \Modules\Core\Services\Diagnostics\SystemHealthService::class
        );
    }

    public function boot(): void
    {
        // Daftarkan pengawas antrean global terpusat sesuai mandat EPRD-CORE-009
        Event::listen(JobFailed::class, QueueWatchdogListener::class);

        // Daftarkan sistem macro UUID v7 database secara global
        UuidBlueprintMacro::register();


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
                \Modules\Core\Tenancy\Console\TenantProvisionCommand::class,
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
        $migrationPaths = [
            base_path('Modules/Core/Database/Migrations'),
            base_path('Modules/Core/Tenancy/Database/Migrations'),
            base_path('Modules/Core/Authorization/Database/Migrations'),
            base_path('Modules/Core/Person/Database/Migrations'),
            base_path('Modules/Core/Platform/Database/Migrations'),
            base_path('Modules/HR/Database/Migrations'),
            base_path('Modules/Academic/Database/Migrations'),
        ];

        foreach ($migrationPaths as $migrationPath) {
            if (!is_dir($migrationPath)) {
                Log::warning(
                    'Migration directory not found.',
                    [
                        'path' => $migrationPath,
                    ]
                );

                continue;
            }

            $this->loadMigrationsFrom($migrationPath);
        }
    }
}

class RouteServiceProvider extends ServiceProvider
{
    protected string $moduleNamespace = 'Modules\Core\Http\Controllers';

    public function boot(): void
    {
        $this->mapApiRoutes();
    }

    protected function mapApiRoutes(): void
    {
        \Illuminate\Support\Facades\Route::prefix('api')
            ->middleware('api')
            ->namespace($this->moduleNamespace ?? 'Modules\Core\Http\Controllers')
            ->group(base_path('Modules/Core/Routes/api.php')); // <-- DIUBAH MENJADI BASE_PATH
    }
}
