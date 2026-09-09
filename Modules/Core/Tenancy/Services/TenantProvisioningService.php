<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Tenancy\Exceptions\InvalidInitialTenantAdminException;
use RuntimeException;

final class TenantProvisioningService
{
    private const ADMIN_ROLE_NAME = 'admin';

    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly ActiveUserResolverInterface $activeUserResolver,
        private readonly MembershipRoleRepositoryInterface $membershipRoleRepository,
    ) {}

    /**
     * Provision a tenant with one explicit initial tenant administrator.
     *
     * The caller supplies an existing active User account. Membership remains
     * Person-owned, while the User identifier is only used to resolve that
     * canonical Person. Tenant creation, Membership creation, and admin role
     * assignment are committed atomically.
     *
     * @param array<string, mixed> $tenantData
     *
     * @return array{
     *     tenant: array<string, mixed>,
     *     initial_admin: array{
     *         user_id: string,
     *         person_id: string,
     *         membership_id: string
     *     }
     * }
     */
    public function provision(
        array $tenantData,
        string $initialAdminUserId,
    ): array {
        $user = $this->activeUserResolver->findActiveById(
            $initialAdminUserId,
        );

        if ($user === null) {
            throw InvalidInitialTenantAdminException::unavailable();
        }

        $person = $user->person;

        if (
            $person === null
            || $person->status !== PersonStatus::ACTIVE->value
        ) {
            throw InvalidInitialTenantAdminException::unavailable();
        }

        $adminRole = $this->requireAdminRole();

        return DB::transaction(
            fn(): array => $this->provisionTenantForPerson(
                $tenantData,
                $person,
                $adminRole,
                (string) $user->id,
            ),
        );
    }

    /**
     * Provision a tenant AND a brand-new initial tenant administrator in one
     * atomic operation.
     *
     * Menutup celah operasional: sebelumnya satu-satunya cara mendaftarkan
     * tenant baru mengharuskan `User` admin SUDAH ADA lebih dulu — tidak ada
     * jalur HTTP maupun CLI untuk membuat User (dengan email+password) baru
     * sekaligus. Method ini membuat Person+User BARU (password di-hash lewat
     * cast model `User::casts()`), lalu memakai jalur provisioning yang
     * SAMA PERSIS dengan `provision()` untuk sisanya — supaya invarian
     * "tenant+membership+role admin selalu tercipta bersamaan" tetap satu
     * sumber kebenaran, bukan duplikat logika yang bisa menyimpang.
     *
     * @param array<string, mixed> $tenantData
     * @param array{name: string, email: string, password: string} $adminData
     *
     * @return array{
     *     tenant: array<string, mixed>,
     *     initial_admin: array{
     *         user_id: string,
     *         person_id: string,
     *         membership_id: string
     *     }
     * }
     */
    public function provisionWithNewAdmin(
        array $tenantData,
        array $adminData,
    ): array {
        $adminRole = $this->requireAdminRole();

        return DB::transaction(function () use ($tenantData, $adminData, $adminRole): array {
            $person = PersonModel::query()->create([
                'name' => $adminData['name'],
                'status' => PersonStatus::ACTIVE->value,
            ]);

            $user = User::query()->create([
                'person_id' => (string) $person->id,
                'email' => $adminData['email'],
                // `password` di-cast 'hashed' pada model User — TIDAK
                // PERNAH panggil Hash::make() manual di sini, supaya
                // tidak ada risiko hash ganda.
                'password' => $adminData['password'],
            ]);

            return $this->provisionTenantForPerson(
                $tenantData,
                $person,
                $adminRole,
                (string) $user->id,
            );
        });
    }

    /**
     * §Perbaikan pasca-Step D: role KUSTOM milik tenant boleh
     * memakai nama apa saja, termasuk (secara teknis) "admin" —
     * partial unique index hanya menjaga keunikan nama DI ANTARA
     * role kustom tenant yang sama, TIDAK mencegah tenant lain
     * memakai nama yang sama persis dengan role sistem. Tanpa
     * `whereNull('tenant_id')`, pencarian role admin kanonik di sini
     * bisa saja secara tidak sengaja mengambil role kustom milik
     * tenant lain kalau urutan baris kebetulan berbeda.
     */
    private function requireAdminRole(): Role
    {
        $adminRole = Role::query()
            ->whereNull('tenant_id')
            ->where('name', self::ADMIN_ROLE_NAME)
            ->first();

        if ($adminRole === null) {
            throw new RuntimeException(
                'Canonical admin role is unavailable.',
            );
        }

        return $adminRole;
    }

    /**
     * Inti provisioning yang dipakai BERSAMA oleh `provision()` (User sudah
     * ada) dan `provisionWithNewAdmin()` (User baru dibuat) — satu-satunya
     * tempat yang benar-benar membuat Tenant, Membership, dan menetapkan
     * role admin.
     *
     * @param array<string, mixed> $tenantData
     *
     * @return array{
     *     tenant: array<string, mixed>,
     *     initial_admin: array{
     *         user_id: string,
     *         person_id: string,
     *         membership_id: string
     *     }
     * }
     */
    private function provisionTenantForPerson(
        array $tenantData,
        PersonModel $person,
        Role $adminRole,
        string $userId,
    ): array {
        $tenant = $this->tenantManager->createTenant(
            $tenantData,
        );

        $tenantId = (string) $tenant['id'];

        $membership = Membership::query()->create([
            'person_id' => (string) $person->id,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
        ]);

        $this->membershipRoleRepository->assignRole(
            (string) $membership->id,
            $tenantId,
            (string) $adminRole->id,
        );

        return [
            'tenant' => $tenant,
            'initial_admin' => [
                'user_id' => $userId,
                'person_id' => (string) $person->id,
                'membership_id' => (string) $membership->id,
            ],
        ];
    }
}
