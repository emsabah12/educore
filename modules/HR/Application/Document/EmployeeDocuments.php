<?php

namespace Modules\HR\Application\Document;

use Carbon\CarbonImmutable;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Domain\Catalog\DocumentType;
use Modules\HR\Domain\Document\EmployeeDocument;

/**
 * Daftar dokumen seorang pegawai untuk tampilan (PRD-001 §15, HR-2a). Hak akses dicek pemanggil.
 *
 * Status masa berlaku [ASUMSI disetujui]: `HABIS` bila "berlaku sampai" sudah lewat, `SEGERA` bila
 * habis dalam ≤ 60 hari (termasuk hari ini), selain itu null.
 *
 * @phpstan-type DocumentRow array{
 *     id: string,
 *     type_id: string,
 *     type: string,
 *     number: string|null,
 *     title: string,
 *     issued_on: string|null,
 *     valid_from: string|null,
 *     valid_until: string|null,
 *     note: string|null,
 *     file_name: string,
 *     file_size: int,
 *     expiry: 'HABIS'|'SEGERA'|null,
 *     voided: bool,
 *     void_reason: string|null,
 *     voided_at: string|null,
 *     uploaded_at: string|null
 * }
 */
final class EmployeeDocuments
{
    public const EXPIRING_DAYS = 60;

    /**
     * Dokumen berlaku lebih dulu (tanggal terbit — atau berlaku mulai — terbaru di atas), lalu yang dibatalkan.
     *
     * @return list<DocumentRow>
     */
    public function forEmployee(string $tenantId, string $employeeId): array
    {
        $documents = EmployeeDocument::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->orderByRaw('voided_at IS NOT NULL')
            ->orderByRaw('COALESCE(issued_on, valid_from) DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $types = DocumentType::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->pluck('name', 'id')
            ->all();

        $today = BusinessClock::today();
        $rows = [];

        foreach ($documents as $document) {
            $rows[] = [
                'id' => $document->id,
                'type_id' => $document->document_type_id,
                'type' => (string) ($types[$document->document_type_id] ?? '–'),
                'number' => $document->number,
                'title' => $document->title,
                'issued_on' => $document->issued_on?->toDateString(),
                'valid_from' => $document->valid_from?->toDateString(),
                'valid_until' => $document->valid_until?->toDateString(),
                'note' => $document->note,
                'file_name' => $document->original_name,
                'file_size' => $document->byte_size,
                'expiry' => $document->isVoided() ? null : self::expiry($document->valid_until, $today),
                'voided' => $document->isVoided(),
                'void_reason' => $document->void_reason,
                'voided_at' => $document->voided_at?->setTimezone(BusinessClock::timezone())->toDateString(),
                'uploaded_at' => $document->created_at?->setTimezone(BusinessClock::timezone())->toDateString(),
            ];
        }

        return $rows;
    }

    /**
     * @return 'HABIS'|'SEGERA'|null
     */
    public static function expiry(?CarbonImmutable $validUntil, CarbonImmutable $today): ?string
    {
        if ($validUntil === null) {
            return null;
        }

        $until = $validUntil->toDateString();

        if ($until < $today->toDateString()) {
            return 'HABIS';
        }

        return $until <= $today->addDays(self::EXPIRING_DAYS)->toDateString() ? 'SEGERA' : null;
    }
}
