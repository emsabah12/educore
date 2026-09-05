<?php

declare(strict_types=1);

namespace Modules\HR\Contracts;

interface RecruitmentCandidateIdentifierRepositoryInterface
{
    /**
     * Menyimpan identifier baru untuk Candidate. $rawValue dienkripsi
     * dan di-fingerprint sebelum disimpan — pemanggil TIDAK PERNAH
     * menulis encrypted_value atau value_fingerprint secara langsung.
     *
     * @return array{id:string,candidate_id:string,type:string,issuing_country_code:string,status:string}
     *
     * @throws \RuntimeException Jika identifier (tenant + type + country
     *                            + value) sudah dimiliki Candidate lain
     *                            (INV-REC-003).
     */
    public function store(
        string $tenantId,
        string $candidateId,
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): array;

    /**
     * Exact-match lookup lewat fingerprint dalam tenant yang sama —
     * dipakai untuk duplicate detection TANPA pernah membaca/menyimpan
     * raw value pemanggil (INV-REC-003: exactness, bukan fuzzy).
     */
    public function existsByFingerprint(
        string $tenantId,
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): bool;

    /**
     * Mencari Candidate (tenant yang sama) yang punya identifier kuat
     * ini — dipakai untuk deteksi "kandidat ini sudah pernah melamar
     * sebelumnya dengan identitas yang sama persis".
     */
    public function findCandidateIdByFingerprint(
        string $tenantId,
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): ?string;
}
