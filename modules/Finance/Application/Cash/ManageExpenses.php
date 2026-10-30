<?php

namespace Modules\Finance\Application\Cash;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Cash\CashEntrySource;
use Modules\Finance\Domain\Cash\ExpenseStatus;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Payment\PaymentStatus;
use stdClass;
use Throwable;

/**
 * Pengajuan → persetujuan → realisasi pengeluaran (PRD-004 §4, OD-KS-03/04/06/08, KS-3). Hak akses (cash.manage /
 * expenses.approve di kas itu) dicek pemanggil; aturan "tidak menyetujui pengajuan sendiri" dicek di sini.
 *
 * Aturan [ASUMSI KS-3]:
 * - ajukan: kategori KELUAR aktif, uraian 3–200, jumlah Rp 1–999.999.999.999, rencana tanggal opsional (±1 tahun),
 *   lampiran opsional (PDF/JPG/PNG ≤ 2 MB);
 * - batalkan: hanya selama Menunggu persetujuan;
 * - setujui: jumlah disetujui Rp 1 s.d. jumlah diajukan, catatan opsional; tolak: catatan wajib 1–200; kas lembaga
 *   di atas batas berjenjang → persetujuan kedua tingkat Yayasan (KS-5, lihat approve());
 * - realisasi: rekening aktif kas itu, tanggal tidak di masa depan, tidak sebelum tanggal pengajuan & tanggal saldo
 *   awal rekening, jumlah Rp 1 s.d. jumlah disetujui, bukti opsional; ditolak bila saldo rekening kurang (OD-KS-08);
 * - batalkan realisasi: alasan 1–200 → transaksi kas BATAL, pengajuan kembali Disetujui (bukti lama dihapus).
 */
final class ManageExpenses
{
    public const DESCRIPTION_MAX_LENGTH = 200;

    public const NOTE_MAX_LENGTH = 200;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CashBalances $balances,
        private readonly ExpenseFileStore $files,
        private readonly ExpenseApprovalLimitSetting $limits,
    ) {}

    /**
     * @throws FinanceException
     */
    public function request(string $tenantId, ?string $organizationId, string $categoryId, string $description, mixed $amount, ?string $neededOn, ?UploadedFile $attachment, ?string $membershipId): string
    {
        $description = $this->text($description, 'description', 'Uraian', 3, self::DESCRIPTION_MAX_LENGTH);
        $value = Rupiah::parse($amount, 'amount', 'jumlah', 1, ManageCashAccounts::MAX_AMOUNT);
        $neededDate = $neededOn === null || trim($neededOn) === '' ? null : $this->neededOn($neededOn);

        $category = Str::isUuid($categoryId)
            ? DB::table('fn_cash_categories')->where('tenant_id', $tenantId)->where('id', $categoryId)->first()
            : null;

        if ($category === null || (string) $category->direction !== CashDirection::Out->value || ! (bool) $category->is_active || (bool) $category->is_system) {
            throw FinanceException::make('category_id', 'Pilih kategori pengeluaran yang aktif.');
        }

        $file = $attachment === null ? null : $this->files->store($tenantId, $attachment, 'attachment');
        $id = (string) Str::uuid7();

        try {
            DB::transaction(function () use ($tenantId, $organizationId, $categoryId, $description, $value, $neededDate, $file, $membershipId, $id, $category): void {
                DB::table('fn_expense_requests')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'organization_id' => $organizationId,
                    'category_id' => $categoryId,
                    'description' => $description,
                    'amount' => $value,
                    'needed_on' => $neededDate,
                    'status' => ExpenseStatus::Requested->value,
                    'attachment_path' => $file['path'] ?? null,
                    'attachment_name' => $file['name'] ?? null,
                    'requested_by_membership_id' => $membershipId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->audit->record(
                    FinanceAuditAction::EXPENSE_REQUESTED,
                    FinanceAuditAction::SUBJECT_EXPENSE,
                    $id,
                    ['uraian' => $description, 'kategori' => (string) $category->name, 'jumlah' => $value],
                    organizationId: $organizationId,
                    tenantId: $tenantId,
                );
            });
        } catch (Throwable $exception) {
            if ($file !== null) {
                $this->files->delete($file['path']);
            }

            throw $exception;
        }

        return $id;
    }

    /**
     * @throws FinanceException
     */
    public function cancel(string $tenantId, string $requestId): void
    {
        DB::transaction(function () use ($tenantId, $requestId): void {
            $request = $this->lock($tenantId, $requestId);

            if (! in_array((string) $request->status, [ExpenseStatus::Requested->value, ExpenseStatus::AwaitingFoundation->value], true)) {
                throw FinanceException::make('form', 'Hanya pengajuan yang masih menunggu persetujuan yang bisa dibatalkan.');
            }

            // Dibatalkan saat menunggu Yayasan: jumlah tahap 1 tetap di first_approved_amount.
            DB::table('fn_expense_requests')->where('id', $requestId)->update(['status' => ExpenseStatus::Cancelled->value, 'approved_amount' => null, 'updated_at' => now()]);

            $this->audit->record(FinanceAuditAction::EXPENSE_CANCELLED, FinanceAuditAction::SUBJECT_EXPENSE, $requestId, ['uraian' => (string) $request->description], organizationId: $this->org($request), tenantId: $tenantId);
        });
    }

    /**
     * Setujui. $foundationLevel = penyetuju punya izin setujui tingkat Yayasan (dicek pemanggil).
     *
     * Berjenjang (KS-5): kas lembaga, penyetuju bukan tingkat Yayasan, dan jumlah disetujui di atas batas
     * `finance.expense_approval_limit` → MENUNGGU_YAYASAN; persetujuan kedua oleh penyetuju tingkat Yayasan yang
     * bukan pengaju dan bukan penyetuju pertama (boleh menurunkan jumlah lagi).
     *
     * @throws FinanceException
     */
    public function approve(string $tenantId, string $requestId, mixed $amount, ?string $note, ?string $membershipId, bool $foundationLevel = false): void
    {
        $note = $note === null || trim($note) === '' ? null : $this->text($note, 'decision_note', 'Catatan', 1, self::NOTE_MAX_LENGTH);

        DB::transaction(function () use ($tenantId, $requestId, $amount, $note, $membershipId, $foundationLevel): void {
            $request = $this->lockForDecision($tenantId, $requestId, $membershipId, $foundationLevel);
            $second = (string) $request->status === ExpenseStatus::AwaitingFoundation->value;
            $max = $second ? (int) $request->approved_amount : (int) $request->amount;
            $value = Rupiah::parse($amount, 'approved_amount', 'jumlah disetujui', 1, $max);
            $organizationId = $this->org($request);
            $limit = $organizationId === null ? 0 : $this->limits->for($tenantId, $organizationId);
            $escalate = ! $second && ! $foundationLevel && $limit > 0 && $value > $limit;

            if ($escalate) {
                DB::table('fn_expense_requests')->where('id', $requestId)->update([
                    'status' => ExpenseStatus::AwaitingFoundation->value,
                    'approved_amount' => $value,
                    'first_approved_amount' => $value,
                    'first_decision_note' => $note,
                    'first_decided_by_membership_id' => $membershipId,
                    'first_decided_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('fn_expense_requests')->where('id', $requestId)->update([
                    'status' => ExpenseStatus::Approved->value,
                    'approved_amount' => $value,
                    'decision_note' => $note,
                    'decided_by_membership_id' => $membershipId,
                    'decided_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record(
                FinanceAuditAction::EXPENSE_APPROVED,
                FinanceAuditAction::SUBJECT_EXPENSE,
                $requestId,
                ['uraian' => (string) $request->description, 'diajukan' => (int) $request->amount, 'disetujui' => $value]
                    + ($escalate ? ['tingkat' => 'lembaga', 'batas' => $limit, 'lanjut' => 'Yayasan'] : [])
                    + ($second ? ['tingkat' => 'Yayasan'] : []),
                organizationId: $organizationId,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws FinanceException
     */
    public function reject(string $tenantId, string $requestId, string $note, ?string $membershipId, bool $foundationLevel = false): void
    {
        $note = $this->text($note, 'decision_note', 'Alasan penolakan', 1, self::NOTE_MAX_LENGTH);

        DB::transaction(function () use ($tenantId, $requestId, $note, $membershipId, $foundationLevel): void {
            $request = $this->lockForDecision($tenantId, $requestId, $membershipId, $foundationLevel);

            DB::table('fn_expense_requests')->where('id', $requestId)->update([
                'status' => ExpenseStatus::Rejected->value,
                // Ditolak di tahap Yayasan: jumlah tahap 1 tetap di first_approved_amount.
                'approved_amount' => null,
                'decision_note' => $note,
                'decided_by_membership_id' => $membershipId,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::EXPENSE_REJECTED,
                FinanceAuditAction::SUBJECT_EXPENSE,
                $requestId,
                ['uraian' => (string) $request->description, 'jumlah' => (int) $request->amount, 'alasan' => $note],
                organizationId: $this->org($request),
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws FinanceException
     */
    public function realize(string $tenantId, string $requestId, string $accountId, string $date, mixed $amount, ?UploadedFile $proof, ?string $membershipId): void
    {
        $day = $this->paidDate($date);
        $file = $proof === null ? null : $this->files->store($tenantId, $proof, 'proof');
        $oldProof = null;

        try {
            DB::transaction(function () use ($tenantId, $requestId, $accountId, $day, $amount, $file, $membershipId, &$oldProof): void {
                $request = $this->lock($tenantId, $requestId);

                if ((string) $request->status !== ExpenseStatus::Approved->value) {
                    throw FinanceException::make('form', 'Hanya pengajuan yang sudah disetujui dan belum direalisasi yang bisa direalisasi.');
                }

                $value = Rupiah::parse($amount, 'amount', 'jumlah realisasi', 1, (int) $request->approved_amount);

                $account = Str::isUuid($accountId)
                    ? DB::table('fn_cash_accounts')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $accountId)
                        ->when($request->organization_id === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $request->organization_id))
                        ->lockForUpdate()
                        ->first()
                    : null;

                if ($account === null || ! (bool) $account->is_active) {
                    throw FinanceException::make('account_id', 'Pilih rekening kas ini yang aktif.');
                }

                $requestedOn = CarbonImmutable::parse((string) $request->created_at)->setTimezone(BusinessClock::timezone())->toDateString();

                if ($day < $requestedOn) {
                    throw FinanceException::make('entry_date', "Tanggal keluar tidak boleh sebelum tanggal pengajuan ({$requestedOn}).");
                }

                if ($day < substr((string) $account->opening_date, 0, 10)) {
                    throw FinanceException::make('entry_date', 'Tanggal keluar tidak boleh sebelum tanggal saldo awal rekening ('.substr((string) $account->opening_date, 0, 10).').');
                }

                $balance = $this->balances->balance((string) $account->id);

                if ($balance < $value) {
                    throw FinanceException::make('amount', 'Saldo '.((string) $account->name).' tidak cukup ('.Rupiah::format($balance).').');
                }

                $entryId = (string) Str::uuid7();

                DB::table('fn_cash_entries')->insert([
                    'id' => $entryId,
                    'tenant_id' => $tenantId,
                    'organization_id' => $request->organization_id,
                    'account_id' => (string) $account->id,
                    'category_id' => (string) $request->category_id,
                    'entry_date' => $day,
                    'direction' => CashDirection::Out->value,
                    'amount' => $value,
                    'description' => mb_substr('Pengeluaran — '.((string) $request->description), 0, ManageCashEntries::DESCRIPTION_MAX_LENGTH),
                    'source' => CashEntrySource::Expense->value,
                    'source_id' => $requestId,
                    'status' => PaymentStatus::Valid->value,
                    'recorded_by_membership_id' => $membershipId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $values = ['status' => ExpenseStatus::Realized->value, 'realized_entry_id' => $entryId, 'updated_at' => now()];

                if ($file !== null) {
                    $oldProof = $request->proof_path === null ? null : (string) $request->proof_path;
                    $values += ['proof_path' => $file['path'], 'proof_name' => $file['name']];
                }

                DB::table('fn_expense_requests')->where('id', $requestId)->update($values);

                $this->audit->record(
                    FinanceAuditAction::EXPENSE_REALIZED,
                    FinanceAuditAction::SUBJECT_EXPENSE,
                    $requestId,
                    ['uraian' => (string) $request->description, 'rekening' => (string) $account->name, 'jumlah' => $value, 'tanggal' => $day],
                    organizationId: $this->org($request),
                    tenantId: $tenantId,
                );
            });
        } catch (Throwable $exception) {
            if ($file !== null) {
                $this->files->delete($file['path']);
            }

            throw $exception;
        }

        if ($oldProof !== null) {
            $this->files->delete($oldProof);
        }
    }

    /**
     * @throws FinanceException
     */
    public function voidRealization(string $tenantId, string $requestId, string $reason, ?string $membershipId): void
    {
        $reason = $this->text($reason, 'reason', 'Alasan pembatalan', 1, self::NOTE_MAX_LENGTH);
        $proof = null;

        DB::transaction(function () use ($tenantId, $requestId, $reason, $membershipId, &$proof): void {
            $request = $this->lock($tenantId, $requestId);

            if ((string) $request->status !== ExpenseStatus::Realized->value || $request->realized_entry_id === null) {
                throw FinanceException::make('form', 'Pengajuan ini belum direalisasi.');
            }

            $entry = DB::table('fn_cash_entries')->where('id', $request->realized_entry_id)->first(['id', 'account_id', 'amount', 'entry_date']);

            if ($entry !== null) {
                DB::table('fn_cash_accounts')->where('id', $entry->account_id)->lockForUpdate()->first(['id']);
                DB::table('fn_cash_entries')->where('id', $entry->id)->update([
                    'status' => PaymentStatus::Void->value,
                    'void_reason' => $reason,
                    'voided_at' => now(),
                    'voided_by_membership_id' => $membershipId,
                    'updated_at' => now(),
                ]);
            }

            $proof = $request->proof_path === null ? null : (string) $request->proof_path;

            DB::table('fn_expense_requests')->where('id', $requestId)->update([
                'status' => ExpenseStatus::Approved->value,
                'realized_entry_id' => null,
                'proof_path' => null,
                'proof_name' => null,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::EXPENSE_REALIZATION_VOIDED,
                FinanceAuditAction::SUBJECT_EXPENSE,
                $requestId,
                ['uraian' => (string) $request->description, 'jumlah' => $entry === null ? null : (int) $entry->amount, 'alasan' => $reason],
                organizationId: $this->org($request),
                tenantId: $tenantId,
            );
        });

        if ($proof !== null) {
            $this->files->delete($proof);
        }
    }

    /**
     * Kas pemilik pengajuan & pengajunya (untuk cek hak akses), atau false bila tidak ada di yayasan ini.
     *
     * @return array{organization_id: string|null, requested_by: string|null}|false
     */
    public function ownerOf(string $tenantId, string $requestId): array|false
    {
        $request = Str::isUuid($requestId)
            ? DB::table('fn_expense_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->first(['organization_id', 'requested_by_membership_id'])
            : null;

        return $request === null ? false : [
            'organization_id' => $request->organization_id === null ? null : (string) $request->organization_id,
            'requested_by' => $request->requested_by_membership_id === null ? null : (string) $request->requested_by_membership_id,
        ];
    }

    /**
     * File lampiran/bukti pengajuan: [path absolut, nama unduhan], atau null bila tidak ada.
     *
     * @return array{path: string, name: string}|null
     */
    public function file(string $tenantId, string $requestId, string $kind): ?array
    {
        $request = Str::isUuid($requestId)
            ? DB::table('fn_expense_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->first(['attachment_path', 'attachment_name', 'proof_path', 'proof_name'])
            : null;

        $path = $request === null ? null : ($kind === 'bukti' ? $request->proof_path : $request->attachment_path);
        $name = $request === null ? null : ($kind === 'bukti' ? $request->proof_name : $request->attachment_name);

        if ($path === null || ! $this->files->exists((string) $path)) {
            return null;
        }

        return ['path' => $this->files->absolutePath((string) $path), 'name' => (string) ($name ?? 'lampiran')];
    }

    /**
     * @throws FinanceException
     */
    private function lockForDecision(string $tenantId, string $requestId, ?string $membershipId, bool $foundationLevel): stdClass
    {
        $request = $this->lock($tenantId, $requestId);
        $status = (string) $request->status;

        if ($status !== ExpenseStatus::Requested->value && $status !== ExpenseStatus::AwaitingFoundation->value) {
            throw FinanceException::make('form', 'Pengajuan ini sudah diputus atau dibatalkan.');
        }

        if ($membershipId !== null && $request->requested_by_membership_id !== null && (string) $request->requested_by_membership_id === $membershipId) {
            throw FinanceException::make('form', 'Anda tidak bisa menyetujui atau menolak pengajuan Anda sendiri.');
        }

        if ($status === ExpenseStatus::AwaitingFoundation->value) {
            if (! $foundationLevel) {
                throw FinanceException::make('form', 'Pengajuan ini menunggu persetujuan tingkat Yayasan.');
            }

            if ($membershipId !== null && (string) $request->first_decided_by_membership_id === $membershipId) {
                throw FinanceException::make('form', 'Persetujuan kedua harus oleh orang lain (Anda sudah menyetujui tahap pertama).');
            }
        }

        return $request;
    }

    /**
     * @throws FinanceException
     */
    private function lock(string $tenantId, string $requestId): stdClass
    {
        $request = Str::isUuid($requestId)
            ? DB::table('fn_expense_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->lockForUpdate()->first()
            : null;

        if ($request === null) {
            throw FinanceException::make('form', 'Pengajuan tidak ditemukan.');
        }

        return $request;
    }

    private function org(stdClass $request): ?string
    {
        return $request->organization_id === null ? null : (string) $request->organization_id;
    }

    /**
     * @throws FinanceException
     */
    private function text(string $value, string $field, string $label, int $min, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (mb_strlen($value) < $min || mb_strlen($value) > $max) {
            throw FinanceException::make($field, "{$label} wajib diisi, {$min}–{$max} karakter.");
        }

        return $value;
    }

    /**
     * @throws FinanceException
     */
    private function neededOn(string $value): string
    {
        $date = $this->parseDate($value);
        $today = BusinessClock::today();

        if ($date === null || $date < $today->subYear() || $date > $today->addYear()) {
            throw FinanceException::make('needed_on', 'Rencana tanggal harus tanggal yang benar dalam ±1 tahun dari hari ini.');
        }

        return $date->toDateString();
    }

    /**
     * @throws FinanceException
     */
    private function paidDate(string $value): string
    {
        $date = $this->parseDate($value);

        if ($date === null || $date > BusinessClock::today()) {
            throw FinanceException::make('entry_date', 'Isi tanggal keluar yang benar dan tidak di masa depan.');
        }

        return $date->toDateString();
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            ? CarbonImmutable::parse($value, BusinessClock::timezone())
            : null;
    }
}
