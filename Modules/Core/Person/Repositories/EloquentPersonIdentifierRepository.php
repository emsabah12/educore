<?php

declare(strict_types=1);

namespace Modules\Core\Person\Repositories;

use Illuminate\Database\QueryException;
use Modules\Core\Person\Contracts\PersonIdentifierCipherInterface;
use Modules\Core\Person\Contracts\PersonIdentifierRepositoryInterface;
use Modules\Core\Person\Models\PersonIdentifierModel;
use RuntimeException;

final class EloquentPersonIdentifierRepository implements PersonIdentifierRepositoryInterface
{
    public function __construct(
        private readonly PersonIdentifierModel $model,
        private readonly PersonIdentifierCipherInterface $cipher,
    ) {}

    public function store(
        string $personId,
        string $type,
        string $issuingCountryCode,
        string $rawValue,
        ?string $issuer = null,
    ): array {
        $personId = trim($personId);
        $type = trim($type);
        $issuingCountryCode = strtoupper(trim($issuingCountryCode));
        $rawValue = trim($rawValue);

        if ($personId === '' || $type === '' || $issuingCountryCode === '') {
            throw new RuntimeException(
                'Person identifier requires person_id, type, and issuing_country_code.',
            );
        }

        if ($this->existsByFingerprint($type, $issuingCountryCode, $rawValue)) {
            throw new RuntimeException(
                'This identifier is already registered to another Person.',
            );
        }

        $record = $this->model->newInstance();
        $record->person_id = $personId;
        $record->type = $type;
        $record->issuing_country_code = $issuingCountryCode;
        $record->issuer = $issuer;
        $record->status = 'ACTIVE';

        // encrypted_value / value_fingerprint SENGAJA di-set di luar
        // $fillable — satu-satunya jalur penulisan raw value adalah
        // lewat cipher di sini, tidak pernah lewat mass-assignment.
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
            // Fail-safe terhadap race condition di antara pre-check
            // existsByFingerprint() dan insert (unique constraint DB
            // adalah penjaga integritas terakhir, bukan cuma pre-check).
            throw new RuntimeException(
                'This identifier is already registered to another Person.',
                previous: $e,
            );
        }

        return [
            'id' => (string) $record->getKey(),
            'person_id' => $personId,
            'type' => $type,
            'issuing_country_code' => $issuingCountryCode,
            'issuer' => $issuer,
            'status' => 'ACTIVE',
        ];
    }

    public function existsByFingerprint(
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): bool {
        $fingerprint = $this->cipher->fingerprint(trim($rawValue));

        return $this->model
            ->newQuery()
            ->where('type', trim($type))
            ->where('issuing_country_code', strtoupper(trim($issuingCountryCode)))
            ->where('value_fingerprint', $fingerprint)
            ->exists();
    }

    public function listForPersonWithDecryptedValue(string $personId): array
    {
        $personId = trim($personId);

        if ($personId === '') {
            throw new RuntimeException(
                'Person identifier lookup requires a person_id.',
            );
        }

        return $this->model
            ->newQuery()
            ->where('person_id', $personId)
            ->where('status', 'ACTIVE')
            ->orderBy('created_at')
            ->get()
            ->map(function (PersonIdentifierModel $record): array {
                return [
                    'id' => (string) $record->getKey(),
                    'type' => (string) $record->type,
                    'issuing_country_code' => (string) $record->issuing_country_code,
                    'value' => $this->cipher->decrypt(
                        (string) $record->getAttribute('encrypted_value'),
                    ),
                    'issuer' => $record->issuer,
                    'status' => (string) $record->status,
                ];
            })
            ->all();
    }
}
