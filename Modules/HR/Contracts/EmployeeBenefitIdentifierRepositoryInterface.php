<?php

declare(strict_types=1);

namespace Modules\HR\Contracts;

interface EmployeeBenefitIdentifierRepositoryInterface
{
    /**
     * Menyimpan identifier benefit baru untuk satu
     * EmployeeBenefitParticipation. $rawValue dienkripsi dan
     * di-fingerprint sebelum disimpan — pemanggil TIDAK PERNAH
     * menulis encrypted_value atau value_fingerprint secara langsung.
     *
     * $benefitProgramId WAJIB sama dengan program milik participation
     * yang direferensikan — FK komposit 3-kolom di database menolak
     * insert kalau tidak cocok (lihat migration
     * `create_employee_benefit_identifiers_table`), jadi validasi di
     * sini murni defense-in-depth, bukan satu-satunya penjaga.
     *
     * @return array{id:string,employee_benefit_participation_id:string,identifier_type:string,status:string}
     *
     * @throws \RuntimeException Jika identifier (tenant + program +
     *                           tipe + value) sudah terdaftar (HR-006
     *                           §7.7 "Recommended uniqueness").
     */
    public function store(
        string $tenantId,
        string $participationId,
        string $benefitProgramId,
        string $identifierType,
        string $rawValue,
        ?string $issuer = null,
        ?string $issuedAt = null,
        ?string $expiresAt = null,
    ): array;

    /**
     * Exact-match lookup lewat fingerprint dalam tenant yang sama —
     * dipakai untuk duplicate detection TANPA pernah membaca/
     * menyimpan raw value pemanggil.
     */
    public function existsByFingerprint(
        string $tenantId,
        string $benefitProgramId,
        string $identifierType,
        string $rawValue,
    ): bool;

    /**
     * Daftar identifier ACTIVE milik satu EmployeeBenefitParticipation,
     * dengan value yang SUDAH didekripsi. Pemanggil bertanggung jawab
     * memastikan konteks ini memang berwenang melihat raw identifier
     * sensitif (mis. nomor BPJS) — lihat HR-014 §26 Security & Privacy.
     *
     * @return list<array{identifier_type: string, value: string, issuer: string|null}>
     */
    public function listForParticipationWithDecryptedValue(
        string $tenantId,
        string $participationId,
    ): array;
}
