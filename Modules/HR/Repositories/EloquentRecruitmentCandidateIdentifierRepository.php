<?php

declare(strict_types=1);

namespace Modules\HR\Repositories;

use Illuminate\Database\QueryException;
use Modules\Core\Person\Contracts\PersonIdentifierCipherInterface;
use Modules\HR\Contracts\RecruitmentCandidateIdentifierRepositoryInterface;
use Modules\HR\Models\RecruitmentCandidateIdentifier;
use RuntimeException;

/**
 * HR-003 §7.5. Memakai ulang `PersonIdentifierCipherInterface` milik
 * Core (Modules\Core\Person) — TIDAK membangun primitif enkripsi/
 * fingerprint baru. Struktur method di sini sengaja mencerminkan persis
 * `EloquentPersonIdentifierRepository`, hanya ditambah scoping
 * tenant_id + candidate_id.
 */
final class EloquentRecruitmentCandidateIdentifierRepository implements RecruitmentCandidateIdentifierRepositoryInterface
{
    public function __construct(
        private readonly RecruitmentCandidateIdentifier $model,
        private readonly PersonIdentifierCipherInterface $cipher,
    ) {}

    public function store(
        string $tenantId,
        string $candidateId,
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): array {
        $type = trim($type);
        $issuingCountryCode = strtoupper(trim($issuingCountryCode));

        if ($type === '' || $issuingCountryCode === '') {
            throw new RuntimeException(
                'Candidate identifier requires type and issuing_country_code.',
            );
        }

        if ($this->existsByFingerprint($tenantId, $type, $issuingCountryCode, $rawValue)) {
            throw new RuntimeException(
                'This identifier is already registered to another Candidate.',
            );
        }

        $record = $this->model->newInstance();
        $record->tenant_id = $tenantId;
        $record->candidate_id = $candidateId;
        $record->type = $type;
        $record->issuing_country_code = $issuingCountryCode;
        $record->status = RecruitmentCandidateIdentifier::STATUS_ACTIVE;

        // encrypted_value / value_fingerprint SENGAJA di luar
        // $fillable — satu-satunya jalur penulisan adalah lewat cipher
        // di sini, tidak pernah lewat mass-assignment payload mentah.
        $record->setAttribute(
            'encrypted_value',
            $this->cipher->encrypt($rawValue),
        );
        $record->setAttribute(
            'value_fingerprint',
            $this->cipher->fingerprint($rawValue),
        );

        try {
            $record->save();
        } catch (QueryException $e) {
            // Fail-safe terhadap race condition antara pre-check
            // existsByFingerprint() dan insert — unique constraint DB
            // adalah penjaga integritas terakhir (INV-REC-003).
            throw new RuntimeException(
                'This identifier is already registered to another Candidate.',
                previous: $e,
            );
        }

        return [
            'id' => (string) $record->getKey(),
            'candidate_id' => $candidateId,
            'type' => $type,
            'issuing_country_code' => $issuingCountryCode,
            'status' => RecruitmentCandidateIdentifier::STATUS_ACTIVE,
        ];
    }

    public function existsByFingerprint(
        string $tenantId,
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): bool {
        $fingerprint = $this->cipher->fingerprint(trim($rawValue));

        return $this->model
            ->newQuery()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('type', trim($type))
            ->where('issuing_country_code', strtoupper(trim($issuingCountryCode)))
            ->where('value_fingerprint', $fingerprint)
            ->exists();
    }

    public function findCandidateIdByFingerprint(
        string $tenantId,
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): ?string {
        $fingerprint = $this->cipher->fingerprint(trim($rawValue));

        $candidateId = $this->model
            ->newQuery()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('type', trim($type))
            ->where('issuing_country_code', strtoupper(trim($issuingCountryCode)))
            ->where('value_fingerprint', $fingerprint)
            ->where('status', RecruitmentCandidateIdentifier::STATUS_ACTIVE)
            ->value('candidate_id');

        return is_string($candidateId) ? $candidateId : null;
    }
}
