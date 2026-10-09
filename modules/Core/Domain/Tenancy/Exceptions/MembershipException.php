<?php

namespace Modules\Core\Domain\Tenancy\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan membership & penugasan. Pesannya aman ditampilkan ke pengguna.
 */
final class MembershipException extends DomainException
{
    public static function tenantNotFound(): self
    {
        return new self('Yayasan tidak ditemukan.');
    }

    public static function personNotFound(): self
    {
        return new self('Data orang tidak ditemukan.');
    }

    public static function membershipInactive(): self
    {
        return new self('Keanggotaan di yayasan ini sudah nonaktif.');
    }

    public static function organizationNotFound(): self
    {
        return new self('Lembaga/unit tidak ditemukan di yayasan ini.');
    }

    public static function organizationInactive(): self
    {
        return new self('Lembaga/unit sudah nonaktif.');
    }

    public static function tenantLevelAssignmentNeedsJenjang(): self
    {
        return new self('Penugasan di tingkat Yayasan hanya untuk penugasan fungsional (wajib memilih jenjang).');
    }

    public static function duplicateAssignment(): self
    {
        return new self('Penugasan yang sama sudah ada.');
    }
}
