<?php

namespace Modules\Core\Domain\Authorization\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan saat Admin Yayasan mengubah penugasan & role (PRD-000 §4.7 F5b).
 * Pesannya aman ditampilkan ke pengguna dan menyebut field form terkait ($field).
 */
final class AccessChangeException extends DomainException
{
    private function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function rule(string $field, string $message): self
    {
        return new self($field, $message);
    }

    public static function membershipInactive(): self
    {
        return new self('form', 'Keanggotaan orang ini di yayasan sudah tidak aktif.');
    }

    public static function roleNotOffered(): self
    {
        return new self('role', 'Pilih role dari daftar yang tersedia.');
    }

    public static function tenantRoleAlreadyGranted(): self
    {
        return new self('role', 'Role ini sudah terpasang.');
    }

    public static function tenantRoleNotGranted(): self
    {
        return new self('role', 'Role ini tidak terpasang.');
    }

    public static function cannotRevokeOwnAdmin(): self
    {
        return new self('role', 'Anda tidak bisa mencabut role Admin Yayasan milik Anda sendiri.');
    }

    public static function lastAdmin(): self
    {
        return new self('role', 'Ini Admin Yayasan terakhir. Pasang role Admin Yayasan ke orang lain dulu.');
    }

    public static function assignmentKindInvalid(): self
    {
        return new self('kind', 'Pilih jenis penugasan: struktural atau fungsional.');
    }

    public static function organizationRequired(): self
    {
        return new self('organization_id', 'Pilih lembaga/unit.');
    }

    public static function jenjangRequired(): self
    {
        return new self('jenjang', 'Pilih jenjang yang dibina.');
    }

    public static function duplicateAssignment(): self
    {
        return new self('form', 'Penugasan yang sama sudah ada.');
    }

    public static function assignmentAlreadyRevoked(): self
    {
        return new self('form', 'Penugasan ini sudah dicabut.');
    }
}
