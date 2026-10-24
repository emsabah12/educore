<?php

namespace Modules\HR\Application\Document;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Domain\Employee\EmployeeStatus;

/**
 * Dokumen yang sudah atau akan habis masa berlakunya (PRD-001 §15.2, HR-2b): untuk halaman
 * "Dokumen akan habis" dan kartu di Beranda. Hak akses dicek pemanggil.
 *
 * Yang dihitung [ASUMSI]: dokumen berlaku (tidak dibatalkan) milik pegawai AKTIF, dengan "berlaku
 * sampai" sebelum hari ini (HABIS) atau dalam ≤ 60 hari (SEGERA). Pegawai yang sudah berhenti tidak
 * diingatkan lagi.
 *
 * @phpstan-type ExpiringRow array{
 *     document_id: string,
 *     employee_id: string,
 *     employee_name: string,
 *     employee_number: string|null,
 *     organization: string|null,
 *     type: string,
 *     title: string,
 *     number: string|null,
 *     valid_until: string,
 *     days_left: int,
 *     expiry: 'HABIS'|'SEGERA'
 * }
 */
final class ExpiringDocuments
{
    /**
     * Urut tanggal habis paling awal.
     *
     * @return list<ExpiringRow>
     */
    public function list(string $tenantId): array
    {
        $today = BusinessClock::today();
        $rows = [];

        foreach ($this->query($tenantId)
            ->leftJoin('hr_placements as p', fn ($join) => $join
                ->on('p.employee_id', '=', 'e.id')
                ->where('p.is_primary', true)
                ->whereNull('p.end_date'))
            ->leftJoin('organizations as o', 'o.id', '=', 'p.organization_id')
            ->join('hr_document_types as t', 't.id', '=', 'd.document_type_id')
            ->orderBy('d.valid_until')
            ->orderBy('persons.name')
            ->get([
                'd.id', 'd.employee_id', 'd.title', 'd.number', 'd.valid_until', 'persons.name as employee_name',
                'e.employee_number', 'o.name as organization', 't.name as type',
            ]) as $row) {
            $until = (string) $row->valid_until;
            $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse($until, BusinessClock::timezone()), false);

            $rows[] = [
                'document_id' => (string) $row->id,
                'employee_id' => (string) $row->employee_id,
                'employee_name' => (string) $row->employee_name,
                'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
                'organization' => $row->organization === null ? null : (string) $row->organization,
                'type' => (string) $row->type,
                'title' => (string) $row->title,
                'number' => $row->number === null ? null : (string) $row->number,
                'valid_until' => $until,
                'days_left' => $daysLeft,
                'expiry' => $until < $today->toDateString() ? 'HABIS' : 'SEGERA',
            ];
        }

        return $rows;
    }

    /**
     * @return array{expired: int, expiring: int}
     */
    public function counts(string $tenantId): array
    {
        $today = BusinessClock::todayString();
        $row = $this->query($tenantId)
            ->selectRaw('COUNT(*) FILTER (WHERE d.valid_until < ?) AS expired, COUNT(*) FILTER (WHERE d.valid_until >= ?) AS expiring', [$today, $today])
            ->first();

        return [
            'expired' => (int) ($row->expired ?? 0),
            'expiring' => (int) ($row->expiring ?? 0),
        ];
    }

    /**
     * Dokumen berlaku milik pegawai aktif yang habis paling lambat 60 hari lagi.
     */
    private function query(string $tenantId): Builder
    {
        $limit = BusinessClock::today()->addDays(EmployeeDocuments::EXPIRING_DAYS)->toDateString();

        return DB::table('hr_documents as d')
            ->join('hr_employees as e', 'e.id', '=', 'd.employee_id')
            ->join('memberships', 'memberships.id', '=', 'e.membership_id')
            ->join('persons', 'persons.id', '=', 'memberships.person_id')
            ->where('d.tenant_id', $tenantId)
            ->where('e.status', EmployeeStatus::Active->value)
            ->whereNull('d.voided_at')
            ->whereNotNull('d.valid_until')
            ->where('d.valid_until', '<=', $limit);
    }
}
