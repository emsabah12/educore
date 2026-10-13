<?php

namespace Modules\Core\Application\Audit;

/**
 * Daftar aksi yang dicatat di audit log (OD-15), dengan label Bahasa Indonesia untuk halaman log.
 *
 * Modul lain boleh mencatat aksi sendiri dengan awalan modulnya, mis. `hr.employee.created`;
 * label yang tidak terdaftar di sini ditampilkan apa adanya.
 */
final class AuditAction
{
    public const LOGIN = 'auth.login';

    public const LOGIN_FAILED = 'auth.login_failed';

    public const LOGOUT = 'auth.logout';

    public const PASSWORD_CHANGED = 'account.password_changed';

    public const EMAIL_CHANGED = 'account.email_changed';

    public const TWO_FACTOR_ENABLED = 'account.two_factor_enabled';

    public const TWO_FACTOR_DISABLED = 'account.two_factor_disabled';

    public const ACCOUNT_CREATED = 'account.created';

    public const SUPERADMIN_GRANTED = 'account.superadmin_granted';

    public const ORGANIZATION_CREATED = 'organization.created';

    public const ORGANIZATION_RENAMED = 'organization.renamed';

    public const ORGANIZATION_MOVED = 'organization.moved';

    public const ORGANIZATION_DEACTIVATED = 'organization.deactivated';

    public const ORGANIZATION_ACTIVATED = 'organization.activated';

    public const MEMBERSHIP_GRANTED = 'access.membership_granted';

    public const ASSIGNMENT_CREATED = 'access.assignment_created';

    public const ROLE_GRANTED = 'access.role_granted';

    public const ROLE_REVOKED = 'access.role_revoked';

    public const CATALOG_SYNCED = 'access.catalog_synced';

    public const SETTING_CHANGED = 'setting.changed';

    public const AUDIT_PRUNED = 'audit.pruned';

    /** Aksi tingkat platform yang tidak pernah tampil di log sebuah yayasan (tidak ditawarkan di filter). */
    public const PLATFORM_ONLY = [self::CATALOG_SYNCED, self::AUDIT_PRUNED];

    /**
     * @return array<string, string> aksi => label
     */
    public static function labels(): array
    {
        return [
            self::LOGIN => 'Masuk',
            self::LOGIN_FAILED => 'Gagal masuk',
            self::LOGOUT => 'Keluar',
            self::PASSWORD_CHANGED => 'Kata sandi diubah',
            self::EMAIL_CHANGED => 'Email diubah',
            self::TWO_FACTOR_ENABLED => 'Verifikasi dua langkah diaktifkan',
            self::TWO_FACTOR_DISABLED => 'Verifikasi dua langkah dimatikan',
            self::ACCOUNT_CREATED => 'Akun dibuat',
            self::SUPERADMIN_GRANTED => 'Akun dijadikan Superadmin',
            self::ORGANIZATION_CREATED => 'Lembaga ditambahkan',
            self::ORGANIZATION_RENAMED => 'Nama lembaga diubah',
            self::ORGANIZATION_MOVED => 'Lembaga dipindahkan',
            self::ORGANIZATION_DEACTIVATED => 'Lembaga dinonaktifkan',
            self::ORGANIZATION_ACTIVATED => 'Lembaga diaktifkan kembali',
            self::MEMBERSHIP_GRANTED => 'Anggota didaftarkan',
            self::ASSIGNMENT_CREATED => 'Penugasan dibuat',
            self::ROLE_GRANTED => 'Role dipasang',
            self::ROLE_REVOKED => 'Role dicabut',
            self::CATALOG_SYNCED => 'Katalog akses disinkronkan',
            self::SETTING_CHANGED => 'Aturan diubah',
            self::AUDIT_PRUNED => 'Log audit lama dihapus',
        ];
    }

    public static function label(string $action): string
    {
        return self::labels()[$action] ?? $action;
    }
}
