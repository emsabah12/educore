<?php

declare(strict_types=1);

namespace Modules\HR\Repositories;

use Illuminate\Database\QueryException;
use Modules\Core\Person\Contracts\PersonIdentifierCipherInterface;
use Modules\HR\Contracts\EmployeeBenefitIdentifierRepositoryInterface;
use Modules\HR\Models\EmployeeBenefitIdentifier;
use RuntimeException;

/**
 * HR-006 §7.7. Memakai ulang `PersonIdentifierCipherInterface` milik
 * Core (Modules\Core\Person) — TIDAK membangun primitif enkripsi/
 * fingerprint baru. Struktur method di sini sengaja mencerminkan
 * persis `EloquentRecruitmentCandidateIdentifierRepository`
 * (HR-003 §7.5), hanya ditambah scoping benefit_program_id yang
 * redundan-tapi-tervalidasi (lihat catatan arsitektur di model).
 */
final class EloquentEmployeeBenefitIdentifierRepository implements EmployeeBenefitIdentifierRepositoryInterface
{
    public function __construct(
        private readonly EmployeeBenefitIdentifier $model,
        private readonly PersonIdentifierCipherInterface $cipher,
    ) {}

    public function store(
        string $tenantId,
        string $participationId,
        string $benefitProgramId,
        string $identifierType,
        string $rawValue,
        ?string $issuer = null,
        ?string $issuedAt = null,
        ?string $expiresAt = null,
    ): array {
        $identifierType = trim($identifierType);

        if ($identifierType === '') {
            throw new RuntimeException(
                'Employee benefit identifier requires identifier_type.',
            );
        }

        if ($this->existsByFingerprint($tenantId, $benefitProgramId, $identifierType, $rawValue)) {
            throw new RuntimeException(
                'This benefit identifier is already registered in this tenant.',
            );
        }

        $record = $this->model->newInstance();
        $record->tenant_id = $tenantId;
        $record->employee_benefit_participation_id = $participationId;
        $record->benefit_program_id = $benefitProgramId;
        $record->identifier_type = $identifierType;
        $record->issuer = $issuer;
        $record->issued_at = $issuedAt;
        $record->expires_at = $expiresAt;
        $record->status = EmployeeBenefitIdentifier::STATUS_ACTIVE;

        // encrypted_value / value_fingerprint SENGAJA di luar
        // $fillable — satu-satunya jalur penulisan adalah lewat
        // cipher di sini, tidak pernah lewat mass-assignment payload
        // mentah.
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
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'uq_benefit_identifiers_identity')) {
                // Fail-safe terhadap race condition antara pre-check
                // existsByFingerprint() dan insert — unique constraint
                // DB adalah penjaga integritas terakhir.
                throw new RuntimeException(
                    'This benefit identifier is already registered in this tenant.',
                    previous: $exception,
                );
            }

            if (str_contains($exception->getMessage(), 'fk_benefit_identifiers_participation_program_tenant')) {
                // FK komposit 3-kolom menolak — benefit_program_id
                // yang diberikan TIDAK cocok dengan program milik
                // participation yang direferensikan (atau
                // participation tidak ditemukan di tenant ini sama
                // sekali).
                throw new RuntimeException(
                    'employee_benefit_participation_id and benefit_program_id do not reference a matching, tenant-owned EmployeeBenefitParticipation.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        return [
            'id' => (string) $record->getKey(),
            'employee_benefit_participation_id' => $participationId,
            'identifier_type' => $identifierType,
            'status' => EmployeeBenefitIdentifier::STATUS_ACTIVE,
        ];
    }

    public function existsByFingerprint(
        string $tenantId,
        string $benefitProgramId,
        string $identifierType,
        string $rawValue,
    ): bool {
        $fingerprint = $this->cipher->fingerprint($rawValue);

        return $this->model
            ->newQuery()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('benefit_program_id', $benefitProgramId)
            ->where('identifier_type', trim($identifierType))
            ->where('value_fingerprint', $fingerprint)
            ->exists();
    }

    public function listForParticipationWithDecryptedValue(
        string $tenantId,
        string $participationId,
    ): array {
        return $this->model
            ->newQuery()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('employee_benefit_participation_id', $participationId)
            ->where('status', EmployeeBenefitIdentifier::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->get()
            ->map(fn(EmployeeBenefitIdentifier $record): array => [
                'identifier_type' => (string) $record->identifier_type,
                'value' => $this->cipher->decrypt(
                    (string) $record->getAttribute('encrypted_value'),
                ),
                'issuer' => $record->issuer,
            ])
            ->all();
    }
}
