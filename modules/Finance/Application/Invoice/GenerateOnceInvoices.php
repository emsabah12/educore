<?php

namespace Modules\Finance\Application\Invoice;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\AcademicYears;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Fee\FeeKind;
use Modules\Finance\Domain\Fee\FeeType;
use Modules\Finance\Domain\Invoice\InvoiceStatus;

/**
 * Buat tagihan sekali bayar (mis. daftar ulang, seragam) untuk seluruh lembaga, satu kelas, atau satu
 * siswa dalam satu tahun ajaran (PRD-003 §4, FN-2b). Hak akses (finance.invoices.manage di lembaga itu)
 * dicek pemanggil.
 *
 * Aturan [ASUMSI disetujui 2026-10-30, rincian FN-2b]:
 * - siswa = pendaftaran yang aktif pada **tanggal acuan**: hari ini, atau awal/akhir tahun ajaran bila
 *   hari ini di luar tahun itu; sasaran kelas = siswa yang kelasnya (tahun itu) kelas tersebut;
 * - tarif & potongan sama dengan tagihan bulanan (InvoicePricing); Rp 0 langsung Lunas;
 * - jatuh tempo diisi saat membuat (tanggal apa pun yang benar);
 * - satu tagihan sekali bayar yang belum batal per siswa × jenis biaya × tahun ajaran; yang sudah ada dilewati.
 *
 * @phpstan-import-type Summary from GenerateMonthlyInvoices
 */
final class GenerateOnceInvoices
{
    public const TARGET_ORGANIZATION = 'lembaga';

    public const TARGET_CLASS = 'kelas';

    public const TARGET_STUDENT = 'siswa';

    public function __construct(
        private readonly AcademicYears $years,
        private readonly EnrolledStudents $students,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return Summary
     *
     * @throws FinanceException
     */
    public function plan(string $tenantId, string $organizationId, string $yearId, string $typeId, string $target, ?string $targetId, string $dueDate): array
    {
        return $this->build($tenantId, $organizationId, $yearId, $typeId, $target, $targetId, $dueDate)['summary'];
    }

    /**
     * @return Summary
     *
     * @throws FinanceException
     */
    public function generate(string $tenantId, string $organizationId, string $yearId, string $typeId, string $target, ?string $targetId, string $dueDate, ?string $membershipId): array
    {
        return DB::transaction(function () use ($tenantId, $organizationId, $yearId, $typeId, $target, $targetId, $dueDate, $membershipId): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["finance-invoices:{$organizationId}:{$yearId}:{$typeId}"]);

            $built = $this->build($tenantId, $organizationId, $yearId, $typeId, $target, $targetId, $dueDate);
            $now = now();

            foreach (array_chunk($built['rows'], 500) as $chunk) {
                $rows = [];

                foreach ($chunk as $row) {
                    $rows[] = $row + [
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'organization_id' => $organizationId,
                        'academic_year_id' => $yearId,
                        'fee_type_id' => $typeId,
                        'period' => null,
                        'paid_amount' => 0,
                        'created_by_membership_id' => $membershipId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('fn_invoices')->insert($rows);
            }

            $summary = $built['summary'];

            if ($summary['created'] > 0) {
                $this->audit->record(
                    FinanceAuditAction::INVOICES_GENERATED,
                    FinanceAuditAction::SUBJECT_FEE_TYPE,
                    $typeId,
                    [
                        'jenis_biaya' => $built['type_code'],
                        'lembaga' => $built['organization_code'],
                        'tahun_ajaran' => $built['year_name'],
                        'sasaran' => $target,
                        'jumlah' => $summary['created'],
                        'total' => $summary['total'],
                    ],
                    organizationId: $organizationId,
                    tenantId: $tenantId,
                );
            }

            return $summary;
        });
    }

    /**
     * @return array{summary: Summary, rows: list<array<string, mixed>>, type_code: string, organization_code: string, year_name: string}
     *
     * @throws FinanceException
     */
    private function build(string $tenantId, string $organizationId, string $yearId, string $typeId, string $target, ?string $targetId, string $dueDate): array
    {
        $type = Str::isUuid($typeId) ? FeeType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($typeId)->first() : null;

        if (! $type instanceof FeeType || $type->kind !== FeeKind::Once || ! $type->is_active) {
            throw FinanceException::make('fee_type_id', 'Pilih jenis biaya sekali bayar yang aktif.');
        }

        $organization = Organization::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($organizationId)->first();

        if (! $organization instanceof Organization) {
            throw FinanceException::make('organization_id', 'Pilih lembaga.');
        }

        $year = null;

        foreach ($this->years->forOrganization($tenantId, $organizationId) as $candidate) {
            if ($candidate['id'] === $yearId) {
                $year = $candidate;
            }
        }

        if ($year === null) {
            throw FinanceException::make('academic_year_id', 'Pilih tahun ajaran dari kalender akademik lembaga ini.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) !== 1 || ! checkdate((int) substr($dueDate, 5, 2), (int) substr($dueDate, 8, 2), (int) substr($dueDate, 0, 4))) {
            throw FinanceException::make('due_date', 'Isi tanggal jatuh tempo yang benar.');
        }

        // Tanggal acuan: hari ini, dipotong ke rentang tahun ajaran.
        $reference = min(max(BusinessClock::todayString(), $year['start_date']), $year['end_date']);
        $students = $this->students->activeBetween($tenantId, $organizationId, $yearId, $reference, $reference);

        $students = match ($target) {
            self::TARGET_ORGANIZATION => $students,
            self::TARGET_CLASS => array_values(array_filter($students, fn (array $student): bool => $targetId !== null && $student['class_id'] === $targetId)),
            self::TARGET_STUDENT => array_values(array_filter($students, fn (array $student): bool => $student['enrollment_id'] === $targetId)),
            default => throw FinanceException::make('target', 'Pilih sasaran: seluruh lembaga, satu kelas, atau satu siswa.'),
        };

        if ($target === self::TARGET_CLASS && ! in_array($targetId, array_column($this->students->classes($tenantId, $organizationId, $yearId), 'id'), true)) {
            throw FinanceException::make('class_id', 'Pilih kelas aktif lembaga ini di tahun ajaran itu.');
        }

        if ($target === self::TARGET_STUDENT && $students === []) {
            throw FinanceException::make('enrollment_id', 'Pilih siswa yang terdaftar di lembaga ini pada tahun ajaran itu.');
        }

        $pricing = InvoicePricing::load($typeId, $organizationId, $yearId);
        $existing = $this->existing($typeId, $organizationId, $yearId);
        $rows = [];
        $missing = [];
        $existingCount = 0;
        $free = 0;
        $total = 0;

        foreach ($students as $student) {
            if (isset($existing[$student['enrollment_id']])) {
                $existingCount++;

                continue;
            }

            $price = $pricing->price($student['enrollment_id'], $student['grade']);

            if ($price === null) {
                $missing[] = ['name' => $student['name'], 'class' => $student['class']];

                continue;
            }

            if ($price['amount'] === 0) {
                $free++;
            }

            $total += $price['amount'];
            $rows[] = $price + [
                'enrollment_id' => $student['enrollment_id'],
                'description' => "{$type->name} — {$year['name']}",
                'grade' => $student['grade'],
                'due_date' => $dueDate,
                'status' => $price['amount'] === 0 ? InvoiceStatus::Paid->value : InvoiceStatus::Open->value,
            ];
        }

        return [
            'summary' => [
                'type' => $type->name,
                'months' => [$year['name']],
                'students' => count($students),
                'created' => count($rows),
                'existing' => $existingCount,
                'free' => $free,
                'total' => $total,
                'missing' => array_slice($missing, 0, GenerateMonthlyInvoices::MISSING_LISTED),
                'missing_count' => count($missing),
            ],
            'rows' => $rows,
            'type_code' => $type->code,
            'organization_code' => $organization->code,
            'year_name' => $year['name'],
        ];
    }

    /**
     * Pendaftaran yang sudah punya tagihan sekali bayar (belum batal) untuk jenis × tahun ini.
     *
     * @return array<string, true>
     */
    private function existing(string $typeId, string $organizationId, string $yearId): array
    {
        $existing = [];

        foreach (DB::table('fn_invoices')
            ->where('fee_type_id', $typeId)
            ->where('organization_id', $organizationId)
            ->where('academic_year_id', $yearId)
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->whereNull('period')
            ->pluck('enrollment_id') as $enrollmentId) {
            $existing[(string) $enrollmentId] = true;
        }

        return $existing;
    }
}
