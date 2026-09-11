<?php

declare(strict_types=1);

namespace Modules\Core\Person\Contracts;

interface PersonIdentifierRepositoryInterface
{
    /**
     * Menyimpan identifier baru. $rawValue dienkripsi dan di-fingerprint
     * sebelum disimpan — pemanggil TIDAK PERNAH menulis encrypted_value
     * atau value_fingerprint secara langsung.
     *
     * @return array{id:string,person_id:string,type:string,issuing_country_code:string,issuer:?string,status:string}
     *
     * @throws \RuntimeException Jika identifier (type + country + value)
     *                           sudah dimiliki Person lain.
     */
    public function store(
        string $personId,
        string $type,
        string $issuingCountryCode,
        string $rawValue,
        ?string $issuer = null,
    ): array;

    /**
     * Exact-match lookup lewat fingerprint — dipakai untuk duplicate
     * detection TANPA pernah membaca/menyimpan raw value pemanggil.
     */
    public function existsByFingerprint(
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): bool;

    /**
     * Mencari Person mana yang memiliki identifier kuat ini — dipakai
     * HR-003 §9.2 (PersonIdentityResolutionServiceInterface) untuk
     * resolusi identitas Candidate -> Person canonical saat hiring
     * conversion. Exact match saja (HR-013/HR-003 INV-REC-003), tidak
     * ada fuzzy match.
     */
    public function findPersonIdByFingerprint(
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): ?string;

    /**
     * Daftar identifier milik satu Person, dengan value yang SUDAH
     * didekripsi. Pemanggil bertanggung jawab memastikan konteks ini
     * memang berwenang melihat raw legal identifier (bukan sekadar
     * permission generik) — lihat HR-013-BR-001.
     *
     * @return array<int, array{id:string,type:string,issuing_country_code:string,value:string,issuer:?string,status:string}>
     */
    public function listForPersonWithDecryptedValue(
        string $personId,
    ): array;
}
