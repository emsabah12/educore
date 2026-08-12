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
use Modules\Core\Authorization\Repositories\EloquentMembershipRepository;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Authorization\Repositories\EloquentMembershipRoleRepository;
use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;
use Modules\Core\Authorization\Repositories\EloquentRolePermissionRepository;
use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Authorization\Services\MembershipContextResolver;
use Modules\Core\Platform\Discovery\ModuleDiscovery;
use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestLoader;
use Modules\Core\Manifest\ModuleManifestParser;
use Modules\Core\Platform\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleBootstrapService;
use Modules\Core\Platform\Module\Services\ModuleLoader;
use Modules\Core\Platform\Module\Services\ModuleProviderRegistrar;
use Modules\Core\Services\ModuleRepository;
use Modules\Core\Services\DependencyResolver;
use Modules\Core\Person\Repositories\EloquentPersonRepository;
use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\Core\Person\Contracts\PersonLifecycleEventRepositoryInterface;
use Modules\Core\Person\Repositories\EloquentPersonLifecycleEventRepository;
use Modules\Core\Platform\Console\ModuleListCommand;
use Modules\Core\Platform\Console\ModuleStatusCommand;
use Modules\Core\Tests\Console\TestModuleLoaderCommand;
use Modules\Core\Platform\Console\KernelHealthCheckCommand;
use Modules\Core\Tenancy\Contracts\TenantRuntimeResolverInterface;
use Modules\Core\Tenancy\Infrastructure\EloquentTenantRuntimeResolver;
use Modules\Core\Listeners\QueueWatchdogListener;
use Modules\Core\Support\Uuid\UuidBlueprintMacro;
use Illuminate\Support\Facades\Log;

final class CoreServiceProvider extends ServiceProvider
{
    /**
     * Daftarkan seluruh layanan komponen platform ke dalam IoC Container.
     */
    public function register(): void
    {

        // 1. Komponen Infrastruktur Dasar Sasis (Auto-wired Singletons)
        $this->app->singleton(ModuleDiscovery::class);
        $this->app->singleton(ModuleManifestLoader::class);
        $this->app->singleton(ModuleManifestParser::class);
        $this->app->singleton(ModuleDefinitionFactory::class);
        $this->app->singleton(ModuleLoader::class);
        $this->app->singleton(ModuleBootstrapService::class);
        $this->app->singleton(ModuleProviderRegistrar::class);

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

        if (method_exists($this, 'registerBlueprintMacros')) {
            $this->registerBlueprintMacros();
        };
        // Register installed non-Core module providers from their manifests.
        $this->registerManifestModuleProviders();
        $this->registerCorePlatformBindings();
        $this->registerMigrations();
        // Daftarkan TenantServiceProvider secara internal
        // $this->app->register(\Modules\Core\Providers\TenantServiceProvider::class);
        // 1. Amankan pendaftaran Tenant Context Service Provider
        $this->app->register(TenantServiceProvider::class);
        // 3. Daftarkan Route Service Provider milik Core
        $this->app->register(RouteServiceProvider::class);

        /*
        * Authorization services membaca request-scoped authentication,
        * membership, dan tenant context.
        *
        * Seluruh dependency chain harus memiliki lifecycle scoped agar
        * tidak menyimpan Request atau TenantContext dari request sebelumnya.
        */
        $this->app->scoped(
            MembershipContextResolverInterface::class,
            MembershipContextResolver::class,
        );

        $this->app->scoped(
            AuthorizationContextResolverInterface::class,
            AuthorizationContextResolver::class,
        );

        $this->app->scoped(
            AuthorizationServiceInterface::class,
            AuthorizationService::class,
        );


        $this->app->bind(
            AuthorizationContextInterface::class,
            AuthorizationContext::class,
        );

        $this->app->bind(
            \Modules\Core\Tenancy\Contracts\TenantRepositoryInterface::class,
            \Modules\Core\Repositories\EloquentTenantRepository::class,
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
            TenantRuntimeResolverInterface::class,
            EloquentTenantRuntimeResolver::class,
        );
    }

    /**
     * Register non-Core module providers declared explicitly in module.yaml.
     */
    private function registerManifestModuleProviders(): void
    {
        /** @var ModuleRepository $repository */
        $repository = $this->app->make(ModuleRepository::class);

        /** @var ModuleProviderRegistrar $registrar */
        $registrar = $this->app->make(ModuleProviderRegistrar::class);

        $registrar->register($repository->all());
    }

    /**
     * Register Core-owned platform bindings independently from module activation.
     */
    private function registerCorePlatformBindings(): void
    {
        $this->app->singleton(
            \Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface::class,
            \Modules\Core\Governance\Audit\Persistence\DatabaseAuditTrailService::class
        );

        $this->app->singleton(
            \Modules\Core\Platform\Notification\Contracts\WhatsAppGatewayInterface::class,
            \Modules\Core\Notification\Gateways\UnavailableWhatsAppGateway::class,
        );

        $this->app->singleton(
            \Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface::class,
            \Modules\Core\Notification\Channels\WhatsAppNotificationChannel::class,
        );

        $this->app->singleton(
            \Modules\Core\Platform\Notification\Contracts\NotificationAttemptStoreInterface::class,
            \Modules\Core\Notification\Persistence\DatabaseNotificationAttemptStore::class,
        );

        $this->app->singleton(
            \Modules\Core\Identity\Contracts\ActiveUserResolverInterface::class,
            \Modules\Core\Identity\Infrastructure\EloquentActiveUserResolver::class,
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
                TestModuleLoaderCommand::class,
            ]);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Core\Tenancy\Console\TenantProvisionCommand::class,
            ]);
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
            base_path('Modules/Core/Person/Database/Migrations'),
            base_path('Modules/Core/Identity/Database/Migrations'),
            base_path('Modules/Core/Tenancy/Database/Migrations'),
            base_path('Modules/Core/Authorization/Database/Migrations'),
            base_path('Modules/Core/Governance/Audit/Database/Migrations'),
            base_path('Modules/Core/Notification/Database/Migrations'),
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
