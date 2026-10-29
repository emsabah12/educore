<?php

namespace Modules\Finance\Application\Invoice;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\AcademicYears;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Fee\FeeKind;
use Modules\Finance\Domain\Fee\FeeType;
use Modules\Finance\Domain\Invoice\InvoiceStatus;

/**
 * Buat tagihan bulanan (mis. SPP) untuk satu lembaga × tahun ajaran × jenis biaya × rentang bulan
 * (PRD-003 §4, FN-2a). Hak akses (finance.invoices.manage di lembaga itu) dicek pemanggil.
 *
 * Aturan [ASUMSI disetujui 2026-10-29, rincian FN-2a]:
 * - siswa = pendaftaran di lembaga yang aktif pada sebagian bulan itu (siswa masuk di tengah tahun
 *   ditagih mulai bulan masuknya; yang sudah keluar tidak ditagih bulan sesudahnya);
 * - tarif = tarif tingkat kelas siswa di tahun ajaran itu, bila tidak ada tarif "semua tingkat"; siswa
 *   tanpa tarif dilewati dan disebut di ringkasan;
 * - potongan siswa (FN-1b) disalin: persen dibulatkan ke bawah ke rupiah, nominal maks. sebesar tarif;
 *   tagihan yang menjadi Rp 0 langsung Lunas;
 * - jatuh tempo = tanggal aturan `finance.due_day` di bulan itu;
 * - aman diulang: tagihan yang sudah ada (belum batal) untuk siswa × jenis × bulan yang sama dilewati.
 *
 * @phpstan-type Summary array{type: string, months: list<string>, students: int, created: int, existing: int, free: int, total: int, missing: list<array{name: string, class: string|null}>, missing_count: int}
 */
final class GenerateMonthlyInvoices
{
    /** Paling banyak sekian nama siswa tanpa tarif yang disebut satu per satu. */
    public const MISSING_LISTED = 20;

    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly AcademicYears $years,
        private readonly EnrolledStudents $students,
        private readonly DueDaySetting $dueDay,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Pilihan bulan tahun ajaran: [Y-m => "Juli 2026"].
     *
     * @return list<array{value: string, label: string}>
     */
    public static function monthOptions(string $start, string $end): array
    {
        $month = CarbonImmutable::parse($start)->startOfMonth();
        $last = CarbonImmutable::parse($end)->startOfMonth();
        $options = [];

        while ($month <= $last) {
            $options[] = ['value' => $month->format('Y-m'), 'label' => self::monthLabel($month)];
            $month = $month->addMonth();
        }

        return $options;
    }

    public static function monthLabel(CarbonImmutable $month): string
    {
        return self::MONTHS[$month->month].' '.$month->year;
    }

    /**
     * Pratinjau tanpa menyimpan apa pun.
     *
     * @return Summary
     *
     * @throws FinanceException
     */
    public function plan(string $tenantId, string $organizationId, string $yearId, string $typeId, string $from, string $to): array
    {
        return $this->build($tenantId, $organizationId, $yearId, $typeId, $from, $to)['summary'];
    }

    /**
     * @return Summary
     *
     * @throws FinanceException
     */
    public function generate(string $tenantId, string $organizationId, string $yearId, string $typeId, string $from, string $to, ?string $membershipId): array
    {
        return DB::transaction(function () use ($tenantId, $organizationId, $yearId, $typeId, $from, $to, $membershipId): array {
            // Dua petugas membuat tagihan yang sama bersamaan: yang kedua menunggu lalu melewati yang sudah ada.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["finance-invoices:{$organizationId}:{$yearId}:{$typeId}"]);

            $built = $this->build($tenantId, $organizationId, $yearId, $typeId, $from, $to);
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
                        'bulan' => [$from, $to],
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
    private function build(string $tenantId, string $organizationId, string $yearId, string $typeId, string $from, string $to): array
    {
        $type = Str::isUuid($typeId) ? FeeType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($typeId)->first() : null;

        if (! $type instanceof FeeType || $type->kind !== FeeKind::Monthly || ! $type->is_active) {
            throw FinanceException::make('fee_type_id', 'Pilih jenis biaya bulanan yang aktif.');
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

        $months = $this->months($year['start_date'], $year['end_date'], $from, $to);
        $rates = $this->rates($typeId, $organizationId, $yearId);
        $discounts = $this->discounts($typeId, $organizationId, $yearId);
        $existing = $this->existing($typeId, $organizationId, $yearId);
        $dueDay = $this->dueDay->for($tenantId, $organizationId);

        $rows = [];
        $students = [];
        $missing = [];
        $existingCount = 0;
        $free = 0;
        $total = 0;

        foreach ($months as $month) {
            $windowStart = max($month->toDateString(), $year['start_date']);
            $windowEnd = min($month->endOfMonth()->toDateString(), $year['end_date']);
            $period = $month->toDateString();

            foreach ($this->students->activeBetween($tenantId, $organizationId, $yearId, $windowStart, $windowEnd) as $student) {
                $students[$student['enrollment_id']] = true;

                if (isset($existing[$student['enrollment_id']][$period])) {
                    $existingCount++;

                    continue;
                }

                $base = ($student['grade'] !== null ? ($rates[$student['grade']] ?? null) : null) ?? ($rates['all'] ?? null);

                if ($base === null) {
                    $missing[$student['enrollment_id']] = ['name' => $student['name'], 'class' => $student['class']];

                    continue;
                }

                [$discount, $label] = $this->discount($discounts[$student['enrollment_id']] ?? null, $base);
                $amount = $base - $discount;

                if ($amount === 0) {
                    $free++;
                }

                $total += $amount;
                $rows[] = [
                    'enrollment_id' => $student['enrollment_id'],
                    'period' => $period,
                    'description' => "{$type->name} — ".self::monthLabel($month),
                    'grade' => $student['grade'],
                    'base_amount' => $base,
                    'discount_amount' => $discount,
                    'discount_label' => $label,
                    'amount' => $amount,
                    'due_date' => $month->setDay($dueDay)->toDateString(),
                    'status' => $amount === 0 ? InvoiceStatus::Paid->value : InvoiceStatus::Open->value,
                ];
            }
        }

        return [
            'summary' => [
                'type' => $type->name,
                'months' => array_map(fn (CarbonImmutable $month): string => self::monthLabel($month), $months),
                'students' => count($students),
                'created' => count($rows),
                'existing' => $existingCount,
                'free' => $free,
                'total' => $total,
                'missing' => array_slice(array_values($missing), 0, self::MISSING_LISTED),
                'missing_count' => count($missing),
            ],
            'rows' => $rows,
            'type_code' => $type->code,
            'organization_code' => $organization->code,
            'year_name' => $year['name'],
        ];
    }

    /**
     * Bulan yang diminta, dipotong ke rentang tahun ajaran.
     *
     * @return list<CarbonImmutable> tanggal 1 tiap bulan
     *
     * @throws FinanceException
     */
    private function months(string $yearStart, string $yearEnd, string $from, string $to): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $from) !== 1 || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $to) !== 1 || $from > $to) {
            throw FinanceException::make('from', 'Pilih bulan awal dan akhir (bulan akhir tidak boleh sebelum bulan awal).');
        }

        $allowed = array_column(self::monthOptions($yearStart, $yearEnd), 'value');

        if (! in_array($from, $allowed, true) || ! in_array($to, $allowed, true)) {
            throw FinanceException::make('from', 'Bulan harus di dalam tahun ajaran.');
        }

        $months = [];
        $month = CarbonImmutable::createFromFormat('!Y-m', $from, BusinessClock::timezone()) ?? BusinessClock::today()->startOfMonth();

        while ($month->format('Y-m') <= $to) {
            $months[] = $month;
            $month = $month->addMonth();
        }

        return $months;
    }

    /**
     * @return array<int|string, int> tingkat → nominal; 'all' = semua tingkat
     */
    private function rates(string $typeId, string $organizationId, string $yearId): array
    {
        $rates = [];

        foreach (DB::table('fn_fee_rates')->where('fee_type_id', $typeId)->where('organization_id', $organizationId)->where('academic_year_id', $yearId)->get(['grade', 'amount']) as $rate) {
            $rates[$rate->grade === null ? 'all' : (int) $rate->grade] = (int) $rate->amount;
        }

        return $rates;
    }

    /**
     * @return array<string, array{percent: int|null, amount: int|null}> pendaftaran → potongan
     */
    private function discounts(string $typeId, string $organizationId, string $yearId): array
    {
        $discounts = [];

        foreach (DB::table('fn_discounts')->where('fee_type_id', $typeId)->where('organization_id', $organizationId)->where('academic_year_id', $yearId)->get(['enrollment_id', 'percent', 'amount']) as $discount) {
            $discounts[(string) $discount->enrollment_id] = [
                'percent' => $discount->percent === null ? null : (int) $discount->percent,
                'amount' => $discount->amount === null ? null : (int) $discount->amount,
            ];
        }

        return $discounts;
    }

    /**
     * Tagihan yang sudah ada (belum batal): pendaftaran → [periode => true].
     *
     * @return array<string, array<string, true>>
     */
    private function existing(string $typeId, string $organizationId, string $yearId): array
    {
        $existing = [];

        foreach (DB::table('fn_invoices')
            ->where('fee_type_id', $typeId)
            ->where('organization_id', $organizationId)
            ->where('academic_year_id', $yearId)
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->whereNotNull('period')
            ->get(['enrollment_id', 'period']) as $invoice) {
            $existing[(string) $invoice->enrollment_id][substr((string) $invoice->period, 0, 10)] = true;
        }

        return $existing;
    }

    /**
     * @param  array{percent: int|null, amount: int|null}|null  $discount
     * @return array{0: int, 1: string|null} besar potongan (rupiah) & labelnya
     */
    private function discount(?array $discount, int $base): array
    {
        if ($discount === null) {
            return [0, null];
        }

        if ($discount['percent'] !== null) {
            return [intdiv($base * $discount['percent'], 100), "{$discount['percent']}%"];
        }

        $amount = min((int) $discount['amount'], $base);

        return [$amount, Rupiah::format((int) $discount['amount'])];
    }
}
