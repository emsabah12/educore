<?php

namespace Modules\Core\Application\Authorization;

use Modules\Core\Domain\Authorization\Exceptions\AccessException;

/**
 * Daftar role & permission yang didefinisikan di KODE oleh setiap modul (OD-11).
 *
 * Setiap modul mendaftarkan permission-nya di method boot() ServiceProvider-nya, mis.:
 *
 *   $catalog->permission('hr.employees.view', 'Lihat data pegawai');
 *   $catalog->grant(CoreAccess::ROLE_KEPALA_LEMBAGA, ['hr.employees.view']);
 *
 * Isi katalog disalin ke database oleh `php artisan educore:sync-access`.
 * Gate hanya menangani ability yang terdaftar di sini; ability lain diteruskan
 * ke policy/gate Laravel biasa.
 */
final class AccessCatalog
{
    public const PERMISSION_KEY_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    public const ROLE_KEY_PATTERN = '/^[a-z][a-z0-9-]{1,49}$/';

    /** @var array<string, string> key => nama */
    private array $permissions = [];

    /** @var array<string, string> key => nama */
    private array $roles = [];

    /** @var array<string, array<string, true>> role key => set permission key */
    private array $rolePermissions = [];

    public function permission(string $key, string $name): self
    {
        if (preg_match(self::PERMISSION_KEY_PATTERN, $key) !== 1 || strlen($key) > 100) {
            throw AccessException::invalidPermissionKey($key);
        }

        $this->permissions[$key] = $name;

        return $this;
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    public function role(string $key, string $name, array $permissionKeys = []): self
    {
        if (preg_match(self::ROLE_KEY_PATTERN, $key) !== 1) {
            throw AccessException::invalidRoleKey($key);
        }

        $this->roles[$key] = $name;
        $this->rolePermissions[$key] ??= [];

        return $this->grant($key, $permissionKeys);
    }

    /**
     * Tambahkan permission ke role yang sudah ada. Dipakai modul lain untuk
     * memperluas role bawaan Core, mis. Kepala Lembaga boleh melihat data pegawai.
     *
     * @param  list<string>  $permissionKeys
     */
    public function grant(string $roleKey, array $permissionKeys): self
    {
        if (! isset($this->roles[$roleKey])) {
            throw AccessException::unknownRole($roleKey);
        }

        foreach ($permissionKeys as $permissionKey) {
            if (! $this->hasPermission($permissionKey)) {
                throw AccessException::unknownPermission($permissionKey);
            }

            $this->rolePermissions[$roleKey][$permissionKey] = true;
        }

        return $this;
    }

    public function hasPermission(string $key): bool
    {
        return isset($this->permissions[$key]);
    }

    public function hasRole(string $key): bool
    {
        return isset($this->roles[$key]);
    }

    /**
     * @return array<string, string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /**
     * @return array<string, array{name: string, permissions: list<string>}>
     */
    public function roles(): array
    {
        $roles = [];

        foreach ($this->roles as $key => $name) {
            $roles[$key] = [
                'name' => $name,
                'permissions' => array_keys($this->rolePermissions[$key]),
            ];
        }

        return $roles;
    }
}
