<?php

namespace Modules\HR\Application\Document;

/**
 * Isian dokumen pegawai dari form (PRD-001 §15, HR-2a). Tanggal berformat YYYY-MM-DD atau null.
 */
final readonly class DocumentData
{
    public function __construct(
        public string $documentTypeId,
        public ?string $number,
        public string $title,
        public ?string $issuedOn,
        public ?string $validFrom,
        public ?string $validUntil,
        public ?string $note,
    ) {}
}
